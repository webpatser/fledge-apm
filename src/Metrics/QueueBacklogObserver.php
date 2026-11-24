<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

use Closure;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Loop;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Carbon;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\Context\Context;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;
use WeakReference;

use function Fledge\Async\async;

/**
 * Queue backlog gauges, published by one process per application and
 * environment: `fledge.queue.pending`, `fledge.queue.delayed`,
 * `fledge.queue.reserved` (`{job}`) and `fledge.queue.oldest_age` (`s`, now
 * minus the creation time of the oldest pending job; absent while nothing
 * is pending). One series per configured connection and queue, with
 * `messaging.system`, `messaging.destination.name` and
 * `laravel.queue.connection`, through the cardinality guard.
 *
 * Gauge mechanics. The queries never run inside the metrics collect. A
 * sample (lock renewal plus the size queries) runs on its own cadence and
 * caches its numbers; four observable gauges report the cached numbers when
 * the collect calls them. The collect therefore never waits on the database
 * or Redis, and a down connection (whose connect timeout can be many
 * seconds) cannot hold up the export of every other instrument. A sample
 * runs inline when no fiber is current and in a fresh fiber at the root
 * context (no spans) inside one. Cadence:
 * - Stock `queue:work` (Looping outside any fiber): Looping, once per
 *   interval, before the collect of that Looping. Looping fires between
 *   jobs, so the queries run between jobs and delay the next pop.
 * - Loop-driven processes (torque workers, the Fledge server, daemons): an
 *   unreferenced repeat timer at `metrics.interval`, armed inside a fiber of
 *   the running loop: at once, on the next loop turn for a process marked
 *   from {main}, or at the next detection inside a fiber for one detected
 *   unmarked from {main} (see Metrics::whenLoopDriven()); with fiber-aware drivers the
 *   queries suspend instead of blocking the loop, and a sample still in
 *   flight is never started twice.
 * - Octane: the terminating callback, once per interval (the first sample
 *   too, when no fiber is current, so the first request is not delayed).
 * - A process that drives its own collects through Metrics::collectDue()
 *   (torque's master) never samples: the observer stops, releasing a lock
 *   it took, as soon as the process turns out to be self-driven.
 * The numbers are therefore up to one interval old at export. With delta
 * temporality a gauge exports its last observation per interval; a process
 * that is not the reporter observes nothing, so it exports no data point
 * for these instruments (not zero).
 *
 * Single reporter. A cache lock picks the process that reports:
 * - Key `fledge-apm:queue-backlog:{service}:{environment}` (from
 *   `apm.service`), so two apps or environments sharing a cache store never
 *   block each other. Store: `metrics.queue_backlog.lock_store`, null for the
 *   default cache store.
 * - TTL: the interval plus a grace of half an interval (at least 2 s, at
 *   most 30 s), so a renewal that comes late by timer jitter or a job that
 *   runs a little long does not drop the lock. The owner token is random per
 *   process (regenerated after a fork, so a forked child never shares the
 *   parent's ownership).
 * - Renewal: every sample, the holder extends the lock by owner (`refresh`)
 *   instead of re-acquiring an expiring one, so the reporter stays the same
 *   process for as long as it lives, with no handover per interval.
 *   Everyone else tries to acquire once per sample and stays standby. At
 *   most one process reports at a time.
 * - A process reports only while its own lease is valid: the lease ends
 *   `lease()` seconds after the monotonic time taken before its last
 *   successful renewal, the TTL minus a margin of half the grace (at least
 *   one second). Database, DynamoDB and file locks expire on whole
 *   wall-clock seconds, up to a second before `renewal + ttl`, so the margin
 *   keeps the lease inside the store's expiry and a holder that missed its
 *   renewal goes quiet before anyone else can take over.
 * - Takeover: a clean stop (WorkerStopping, shutdown) releases the lock and a
 *   standby takes over at its next sample, within one interval. A holder
 *   that dies without stopping is replaced once the lock expires at the
 *   next standby sample: a gap of up to about one TTL plus one interval.
 * - A lock store that cannot lock (a store that does not implement
 *   LockProvider, or the `null` store whose locks always succeed): nobody
 *   reports, and a warning is logged through the throttled writer. Every
 *   process reporting would silently multiply the series, one per worker,
 *   which a sum over the gauge cannot tell apart from a real backlog. A lock
 *   store that fails (cache down) is logged and the process does not
 *   report. The `array` store locks per process only, so with it every
 *   process reports; the `file` store locks per host.
 *
 * Failures: a queue connection or a size call that throws is logged through
 * the throttled writer and leaves that connection's remaining queues out of
 * the sample, so a down database or Redis costs one connect timeout per
 * sample, not one per queue; nothing throws into the caller or the collect.
 * A driver without a given size method is skipped quietly for that gauge.
 */
final class QueueBacklogObserver
{
    /** messaging.system of every Laravel queue, as on the job instruments. */
    public const string MESSAGING_SYSTEM = 'laravel-queue';

    /** Prefix of the lock key; service and environment follow. */
    public const string LOCK_PREFIX = 'fledge-apm:queue-backlog';

    /** @var array<string, string> gauge key to queue method */
    private const array SIZES = [
        'pending' => 'pendingSize',
        'delayed' => 'delayedSize',
        'reserved' => 'reservedSize',
    ];

    private readonly int $ttl;

    private readonly float $lease;

    private ?Metrics $metrics = null;

    /** The Loop::defer() id that arms the timer on the next loop turn. */
    private ?string $pendingArm = null;

    /** @var Closure(): float monotonic seconds */
    private readonly Closure $clock;

    private ?CardinalityGuard $guard = null;

    private ?ObservableCallbackInterface $callback = null;

    private bool $started = false;

    private bool $stopped = false;

    private bool $sampling = false;

    private ?float $lastSample = null;

    /** Monotonic time until which this process may report. */
    private float $leaseUntil = 0.0;

    private ?Lock $lock = null;

    private int|false $lockPid = false;

    private bool $held = false;

    private bool $warnedNoLock = false;

    private ?string $timer = null;

    /** @var list<array{attributes: array<non-empty-string, string>, pending?: int, delayed?: int, reserved?: int, created?: float}> */
    private array $values = [];

    private static bool $shutdownRegistered = false;

    /** @var list<WeakReference<self>> */
    private static array $shutdownTargets = [];

    /**
     * @param  array<string, list<string>>  $queues  connection name to queue names
     * @param  Closure(): ?Repository  $lockStore  the cache repository holding the lock, resolved per process
     * @param  (Closure(): float)|null  $clock  monotonic seconds; tests pass a travelling clock
     */
    public function __construct(
        private readonly QueueFactory $manager,
        private readonly array $queues,
        private readonly Closure $lockStore,
        private readonly string $lockKey,
        private readonly int $interval,
        private readonly ?LogWriterInterface $log = null,
        private readonly FiberContextStorage $storage = new FiberContextStorage,
        ?Closure $clock = null,
    ) {
        $this->ttl = self::ttlFor($interval);
        $this->lease = self::leaseFor($interval);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function __destruct()
    {
        $this->cancelTimer();
    }

    /**
     * The configured queues (`metrics.queue_backlog.queues`) with blank
     * names dropped; empty when the group is off.
     *
     * @param  array<string, mixed>  $config  the `apm` config array
     * @return array<string, list<string>>
     */
    public static function configuredQueues(array $config): array
    {
        $metrics = (array) ($config['metrics'] ?? []);

        if (! (bool) ($metrics['groups']['queue_backlog'] ?? true)) {
            return [];
        }

        $queues = [];

        foreach ((array) ($metrics['queue_backlog']['queues'] ?? []) as $connection => $names) {
            $names = array_values(array_unique(array_filter(
                array_map(static fn (mixed $name): string => is_scalar($name) ? trim((string) $name) : '', (array) $names),
                static fn (string $name): bool => $name !== '',
            )));

            if (is_string($connection) && $connection !== '' && $names !== []) {
                $queues[$connection] = $names;
            }
        }

        return $queues;
    }

    /**
     * The lock key for the configured service and environment.
     *
     * @param  array<string, mixed>  $config  the `apm` config array
     */
    public static function lockKeyFor(array $config): string
    {
        $service = (array) ($config['service'] ?? []);
        $slug = static fn (mixed $value, string $default): string => trim(
            (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', is_scalar($value) && (string) $value !== '' ? (string) $value : $default),
            '-',
        );

        return self::LOCK_PREFIX.':'.$slug($service['name'] ?? null, 'laravel').':'.$slug($service['environment'] ?? null, 'production');
    }

    /**
     * Lock TTL in seconds: the interval plus half an interval of grace,
     * the grace between 2 and 30 seconds.
     */
    public static function ttlFor(int $interval): int
    {
        $interval = max(1, $interval);

        return $interval + min(30, max(2, (int) ceil($interval / 2)));
    }

    /**
     * How long a renewal lets this process report, in seconds: the TTL
     * minus half the grace, at least one second, so the local lease ends
     * before a store that expires on whole wall-clock seconds (database,
     * DynamoDB, file) can let another process take the lock. With a grace
     * of at least 2 s the lease is at least the interval plus one second,
     * so it never ends exactly when the next renewal is due.
     */
    public static function leaseFor(int $interval): float
    {
        $ttl = self::ttlFor($interval);
        $grace = $ttl - max(1, $interval);

        return $ttl - max(1.0, $grace / 2);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function lease(): float
    {
        return $this->lease;
    }

    public function lockKey(): string
    {
        return $this->lockKey;
    }

    /**
     * Create the four gauges on the fledge-apm meter and start the sample
     * cadence. Once per instance; never throws.
     *
     * Inside a fiber of the running loop the timer arms and the first sample
     * starts now; called from {main} both wait for the next loop turn in a
     * process marked through markLongRunning(), or for the next detection
     * inside a fiber otherwise (see Metrics::whenLoopDriven()). In an
     * event-driven process (stock worker, Octane) nothing starts, and a
     * timer armed before it turned out to be one is cancelled at once:
     * Looping or the terminating callback takes the samples. A self-driven
     * process (collectDue()) stops the observer at once, or as soon as it
     * turns out to be one.
     */
    public function start(Metrics $metrics): void
    {
        if ($this->started || $this->stopped) {
            return;
        }

        $this->started = true;
        $this->metrics = $metrics;
        $this->guard = $metrics->guard();

        if ($metrics->selfDriven()) {
            $this->stop();

            return;
        }

        $owner = WeakReference::create($this);
        $metrics->whenSelfDriven(static function () use ($owner): void {
            $owner->get()?->stop();
        });
        $metrics->whenEventDriven(static function () use ($owner): void {
            $owner->get()?->cancelTimer();
        });

        try {
            $meter = $metrics->provider()->getMeter(MetricsFactory::INSTRUMENTATION_NAME);

            $this->callback = $meter->batchObserve(
                $this->observe(...),
                $meter->createObservableGauge('fledge.queue.pending', '{job}', 'Jobs ready to be processed'),
                $meter->createObservableGauge('fledge.queue.delayed', '{job}', 'Jobs waiting for their delay to pass'),
                $meter->createObservableGauge('fledge.queue.reserved', '{job}', 'Jobs reserved by a worker'),
                $meter->createObservableGauge('fledge.queue.oldest_age', 's', 'Age of the oldest pending job'),
            );
        } catch (Throwable $e) {
            $this->warn('queue backlog gauges could not be created', $e);
        }

        self::registerShutdown($this);

        $this->pendingArm = $metrics->whenLoopDriven(static function () use ($owner): void {
            $observer = $owner->get();

            if ($observer !== null) {
                $observer->pendingArm = null;
                $observer->startTimer();
                $observer->sampleIfDue();
            }
        });
    }

    /**
     * Stop sampling and reporting, and release the lock when this process
     * holds it, so a standby takes over at its next sample. Never throws.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->leaseUntil = 0.0;
        $this->values = [];
        $this->cancelTimer();

        try {
            $this->callback?->detach();
        } catch (Throwable) {
            // Detaching is best effort.
        }

        $this->callback = null;
        $this->releaseHeld();
    }

    /**
     * Release the lock when this process holds it. A claim suspended in the
     * lock store while stop() ran calls this again when it returns.
     */
    private function releaseHeld(): void
    {
        if ($this->held && $this->lockPid === getmypid()) {
            try {
                $this->lock?->release();
            } catch (Throwable) {
                // The lock expires on its own.
            }
        }

        $this->held = false;
    }

    /**
     * Whether this process reports the gauges right now: it holds the lock
     * and its lease from the last renewal has not run out.
     */
    public function reporting(): bool
    {
        return $this->started && ! $this->stopped && $this->lockPid === getmypid() && ($this->clock)() < $this->leaseUntil;
    }

    /**
     * sample() when about one interval passed since the last one (or there
     * was none): in a background fiber when a fiber is current, inline
     * otherwise (a fiber queued from {main} would only run at the next loop
     * turn, which in a stock worker is some later job's await).
     */
    public function sampleIfDue(): void
    {
        if (! $this->started || $this->stopped || $this->sampling || $this->stopIfSelfDriven()) {
            return;
        }

        // A tenth of an interval of slack, so a timer that fires a little
        // early does not skip a whole interval.
        if ($this->lastSample !== null && ($this->clock)() - $this->lastSample < $this->interval * 0.9) {
            return;
        }

        if (\Fiber::getCurrent() === null) {
            $this->sample();

            return;
        }

        try {
            async(fn (): bool => $this->sample())->ignore();
        } catch (Throwable) {
            // A fiber that cannot start skips this interval.
        }
    }

    /**
     * Renew or acquire the lock and, as the holder, read every configured
     * queue at the root context. True when this process reports.
     */
    public function sample(): bool
    {
        if ($this->stopped || $this->sampling || $this->stopIfSelfDriven()) {
            return false;
        }

        $this->sampling = true;
        $scope = $this->storage->attach(Context::getRoot());

        try {
            $started = ($this->clock)();
            $this->lastSample = $started;

            if (! $this->claim()) {
                $this->leaseUntil = 0.0;
                $this->values = [];

                return false;
            }

            $values = $this->read();

            // stop() may have run while a query was suspended.
            if ($this->stopped) {
                return false;
            }

            $this->values = $values;
            $this->leaseUntil = $started + $this->lease;

            return true;
        } catch (Throwable $e) {
            $this->leaseUntil = 0.0;
            $this->values = [];
            $this->warn('queue backlog sample failed', $e);

            return false;
        } finally {
            $scope->detach();
            $this->sampling = false;
        }
    }

    /**
     * The batch callback the collect runs: cached numbers only, no I/O.
     */
    private function observe(ObserverInterface $pending, ObserverInterface $delayed, ObserverInterface $reserved, ObserverInterface $oldestAge): void
    {
        if (! $this->reporting() || $this->guard === null) {
            return;
        }

        $now = (float) Carbon::now()->format('U.u');

        foreach ($this->values as $row) {
            $attributes = $row['attributes'];

            if (isset($row['pending'])) {
                $pending->observe($row['pending'], $this->guard->attributes('fledge.queue.pending', $attributes));
            }

            if (isset($row['delayed'])) {
                $delayed->observe($row['delayed'], $this->guard->attributes('fledge.queue.delayed', $attributes));
            }

            if (isset($row['reserved'])) {
                $reserved->observe($row['reserved'], $this->guard->attributes('fledge.queue.reserved', $attributes));
            }

            if (isset($row['created'])) {
                $oldestAge->observe(max(0.0, $now - $row['created']), $this->guard->attributes('fledge.queue.oldest_age', $attributes));
            }
        }
    }

    /**
     * Keep or take the lock. False for a standby, a store without locks,
     * and a store that failed.
     */
    private function claim(): bool
    {
        $lock = $this->lock();

        if ($lock === null) {
            return false;
        }

        try {
            if ($this->held && $lock instanceof CacheLock) {
                try {
                    if ($lock->refresh($this->ttl)) {
                        $this->held = true; // stop() may have cleared it meanwhile

                        return $this->keptAfterStop();
                    }
                } catch (RuntimeException) {
                    // A driver without refresh: hand the lock back and race
                    // for it like everyone else, without reporting meanwhile.
                    $this->leaseUntil = 0.0;
                    $lock->release();
                }
            }

            $this->held = (bool) $lock->get();

            return $this->keptAfterStop();
        } catch (Throwable $e) {
            $this->held = false;
            $this->warn('queue backlog lock store failed, this process does not report the backlog', $e);

            return false;
        }
    }

    /**
     * The claim result once the store returned: when stop() ran while the
     * claim was suspended in the store, the lock taken or renewed after it
     * is released here (release is by owner, so this is safe either way).
     */
    private function keptAfterStop(): bool
    {
        if (! $this->held) {
            return false;
        }

        if ($this->stopped) {
            $this->releaseHeld();

            return false;
        }

        return true;
    }

    /**
     * True, after stopping, when the process drives its own collects
     * through collectDue(): such a process never samples.
     */
    private function stopIfSelfDriven(): bool
    {
        if ($this->metrics === null || ! $this->metrics->selfDriven()) {
            return false;
        }

        $this->stop();

        return true;
    }

    /**
     * This process's lock, created on first use and again after a fork
     * (new pid, new owner). Null when the store cannot lock.
     */
    private function lock(): ?Lock
    {
        $pid = getmypid();

        if ($this->lockPid === $pid) {
            return $this->lock;
        }

        $this->lockPid = $pid;
        $this->lock = null;
        $this->held = false;
        $this->leaseUntil = 0.0;

        try {
            $store = ($this->lockStore)()?->getStore();
        } catch (Throwable $e) {
            $this->lockPid = false;
            $this->warn('queue backlog lock store could not be resolved, this process does not report the backlog', $e);

            return null;
        }

        $lock = $store instanceof LockProvider
            ? $store->lock($this->lockKey, $this->ttl, sprintf('%s:%s:%s', gethostname() ?: 'host', $pid, bin2hex(random_bytes(8))))
            : null;

        if ($lock === null || $lock instanceof NoLock) {
            $this->warnNoLock($store === null ? 'none' : get_debug_type($store));

            return null;
        }

        return $this->lock = $lock;
    }

    /**
     * @return list<array{attributes: array<non-empty-string, string>, pending?: int, delayed?: int, reserved?: int, created?: float}>
     */
    private function read(): array
    {
        $rows = [];

        foreach ($this->queues as $connection => $names) {
            try {
                $queue = $this->manager->connection($connection);
            } catch (Throwable $e) {
                $this->warn("queue backlog of connection {$connection} could not be read", $e);

                continue;
            }

            foreach ($names as $name) {
                try {
                    $row = self::readQueue($queue, $name);
                } catch (Throwable $e) {
                    // One down database or Redis costs one connect timeout
                    // per sample, not one per queue.
                    $this->warn("queue backlog of {$connection}:{$name} could not be read, the connection's other queues are skipped this sample", $e);

                    continue 2;
                }

                if ($row !== []) {
                    $rows[] = ['attributes' => [
                        'messaging.system' => self::MESSAGING_SYSTEM,
                        'messaging.destination.name' => $name,
                        'laravel.queue.connection' => $connection,
                    ]] + $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Typed as object: a driver may lack any of the methods.
     *
     * @return array{pending?: int, delayed?: int, reserved?: int, created?: float}
     */
    private static function readQueue(object $queue, string $name): array
    {
        $row = [];

        foreach (self::SIZES as $key => $method) {
            if (method_exists($queue, $method)) {
                $row[$key] = max(0, (int) $queue->{$method}($name));
            }
        }

        if (method_exists($queue, 'creationTimeOfOldestPendingJob')) {
            $created = $queue->creationTimeOfOldestPendingJob($name);

            if (is_numeric($created)) {
                $row['created'] = (float) $created;
            }
        }

        return $row;
    }

    /**
     * Arm the sample timer; only inside a fiber of the running loop (see
     * start()).
     */
    private function startTimer(): void
    {
        if ($this->timer !== null || $this->stopped || \Fiber::getCurrent() === null) {
            return;
        }

        $owner = WeakReference::create($this);

        try {
            $this->timer = Loop::unreference(Loop::repeat((float) $this->interval, static function () use ($owner): void {
                $owner->get()?->timerTick();
            }));
        } catch (Throwable) {
            $this->timer = null;
        }
    }

    /**
     * A timer firing: samples while the process is loop-driven; once it
     * turned out to be event-driven the timer goes (Looping samples), once
     * self-driven the observer stops.
     */
    private function timerTick(): void
    {
        if ($this->metrics !== null && ! $this->metrics->loopDriven()) {
            if (! $this->stopIfSelfDriven()) {
                $this->cancelTimer();
            }

            return;
        }

        $this->sampleIfDue();
    }

    private function cancelTimer(): void
    {
        if ($this->pendingArm !== null) {
            try {
                Loop::cancel($this->pendingArm);
            } catch (Throwable) {
                // Gone with the driver.
            }

            $this->pendingArm = null;
        }

        if ($this->timer === null) {
            return;
        }

        try {
            Loop::cancel($this->timer);
        } catch (Throwable) {
            // Already gone with the loop.
        }

        $this->timer = null;
    }

    private function warnNoLock(string $store): void
    {
        if ($this->warnedNoLock) {
            return;
        }

        $this->warnedNoLock = true;
        $this->write(sprintf(
            'fledge-apm: the queue backlog lock store (%s) does not support locks, so no process reports the queue backlog gauges. '
            .'Set apm.metrics.queue_backlog.lock_store to a store with locks (redis, database, memcached, dynamodb, file on one host).',
            $store,
        ));
    }

    private function warn(string $what, Throwable $e): void
    {
        $this->write(sprintf('fledge-apm: %s: %s: %s', $what, $e::class, mb_substr($e->getMessage(), 0, 200)));
    }

    private function write(string $message): void
    {
        try {
            $this->log?->write(LogLevel::WARNING, $message, []);
        } catch (Throwable) {
            // Logging must never break a sample.
        }
    }

    /**
     * One shutdown function per process that stops (and so releases the
     * lock of) every started observer still alive.
     */
    private static function registerShutdown(self $observer): void
    {
        self::$shutdownTargets = array_values(array_filter(
            self::$shutdownTargets,
            static fn (WeakReference $target): bool => $target->get() !== null,
        ));
        self::$shutdownTargets[] = WeakReference::create($observer);

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$shutdownTargets as $target) {
                $target->get()?->stop();
            }
        });
    }
}
