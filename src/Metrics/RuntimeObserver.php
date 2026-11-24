<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

use Fledge\Fiber\Database\PoolStats;
use Fledge\Fiber\Database\PoolStatsRegistry;
use Fledge\Loop;
use Fledge\Loop\CallbackType;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;
use WeakReference;

/**
 * Runtime instruments of a long-running process, three independent groups:
 *
 * - `metrics.groups.loop` (only in a loop-driven process, see
 *   Metrics::loopDriven(); otherwise it records nothing): `fledge.loop.lag`
 *   (histogram, `s`), the drift of an unreferenced repeat timer, and
 *   `fledge.loop.watchers` (gauge, `{watcher}`, attribute
 *   `fledge.loop.watcher.type`: defer, delay, repeat, readable, writable,
 *   signal, every type reported, zero included). The watcher count is every
 *   registered, non-cancelled callback, disabled and unreferenced ones and
 *   this package's own timers included.
 * - `metrics.groups.runtime`: `fledge.php.memory.usage` and
 *   `fledge.php.memory.peak` (`By`, real allocated size), and the cumulative
 *   garbage collector numbers `fledge.php.gc.runs`, `fledge.php.gc.collected`
 *   and `fledge.php.gc.duration` (`s`) as observable counters; the SDK turns
 *   the cumulative readings into deltas. Works with or without an event loop.
 * - `metrics.groups.database_pool`: the pools of the fledge-fiber registry,
 *   when that release has one; all observable gauges: `db.client.connection.count` (attribute
 *   `db.client.connection.state` idle or used), `db.client.connection.max`
 *   and `db.client.connection.pending_requests`, per
 *   `db.client.connection.pool.name`. Several live pools can share one
 *   connection name (read/write split, per-task connections), so the numbers
 *   are summed per name.
 *
 * Lag. The timer repeats every `$lagPeriod` seconds (1 s by default) and is
 * unreferenced, so it never keeps a loop alive. The loop schedules the next
 * firing one period after the moment it ran the current one, so the expected
 * time of a firing is the previous firing plus the period and the lag is
 * `max(0, now - expected)` on the monotonic clock. The timer arms only
 * inside a fiber of the running loop: at start() when a fiber is current,
 * otherwise on the next loop turn of a marked process or at the next
 * detection inside a fiber (Metrics::whenLoopDriven()). A stock
 * `queue:work` (Looping outside any fiber), an Octane worker driven from
 * {main} and a process that drives its own collects through collectDue()
 * never arm it: a stock worker turns the loop only briefly, from the main
 * context, so the drift would measure blocked {main} time. Should the
 * process turn out to be one of those later, the timer is cancelled at
 * once and the group goes quiet.
 *
 * Nothing here throws into the collect: every callback is guarded.
 */
final class RuntimeObserver
{
    public const string ATTRIBUTE_WATCHER_TYPE = 'fledge.loop.watcher.type';

    public const string ATTRIBUTE_POOL_NAME = 'db.client.connection.pool.name';

    public const string ATTRIBUTE_POOL_STATE = 'db.client.connection.state';

    /** Explicit boundaries of `fledge.loop.lag`, in seconds. */
    public const array LAG_BOUNDARIES = [0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5];

    private ?CardinalityGuard $guard = null;

    private bool $started = false;

    private bool $stopped = false;

    private ?string $timer = null;

    /** The Loop::defer() id that arms the lag timer on the next loop turn. */
    private ?string $pendingArm = null;

    private ?Metrics $metrics = null;

    private ?float $expected = null;

    private ?HistogramInterface $lag = null;

    /** @var list<ObservableCallbackInterface> */
    private array $callbacks = [];

    public function __construct(
        private readonly bool $loop = true,
        private readonly bool $runtime = true,
        private readonly bool $databasePool = true,
        private readonly float $lagPeriod = 1.0,
    ) {}

    public function __destruct()
    {
        $this->cancelTimer();
    }

    /**
     * Which groups are on in the `apm` config array (each defaults to on).
     *
     * @param  array<string, mixed>  $config
     * @return array{loop: bool, runtime: bool, database_pool: bool}
     */
    public static function groups(array $config): array
    {
        $groups = (array) (((array) ($config['metrics'] ?? []))['groups'] ?? []);

        return [
            'loop' => (bool) ($groups['loop'] ?? true),
            'runtime' => (bool) ($groups['runtime'] ?? true),
            'database_pool' => (bool) ($groups['database_pool'] ?? true),
        ];
    }

    /**
     * Create the instruments of the enabled groups and arm the lag timer
     * (see the class docblock for when). Once per instance; never throws.
     */
    public function start(Metrics $metrics): void
    {
        if ($this->started || $this->stopped) {
            return;
        }

        $this->started = true;
        $this->metrics = $metrics;
        $this->guard = $metrics->guard();

        $owner = WeakReference::create($this);
        $cancel = static function () use ($owner): void {
            $owner->get()?->cancelTimer();
        };
        $metrics->whenSelfDriven($cancel);
        $metrics->whenEventDriven($cancel);

        try {
            $meter = $metrics->provider()->getMeter(MetricsFactory::INSTRUMENTATION_NAME);

            if ($this->loop) {
                $this->lag = $meter->createHistogram(
                    'fledge.loop.lag',
                    's',
                    'Delay of an event loop timer behind its schedule',
                    ['ExplicitBucketBoundaries' => self::LAG_BOUNDARIES],
                );
                $this->callbacks[] = $meter->batchObserve(
                    $this->observeWatchers(...),
                    $meter->createObservableGauge('fledge.loop.watchers', '{watcher}', 'Registered event loop watchers by type, disabled and unreferenced ones included'),
                );
            }

            if ($this->runtime) {
                $this->callbacks[] = $meter->batchObserve(
                    $this->observeMemory(...),
                    $meter->createObservableGauge('fledge.php.memory.usage', 'By', 'Memory allocated from the system by the PHP memory manager'),
                    $meter->createObservableGauge('fledge.php.memory.peak', 'By', 'Peak of the memory allocated from the system'),
                );
                $this->callbacks[] = $meter->batchObserve(
                    $this->observeGc(...),
                    $meter->createObservableCounter('fledge.php.gc.runs', '{run}', 'Garbage collector runs'),
                    $meter->createObservableCounter('fledge.php.gc.collected', '{object}', 'Cycles collected by the garbage collector'),
                    $meter->createObservableCounter('fledge.php.gc.duration', 's', 'Time spent in the garbage collector'),
                );
            }

            if ($this->databasePool && self::poolRegistryAvailable()) {
                $this->callbacks[] = $meter->batchObserve(
                    $this->observePools(...),
                    $meter->createObservableGauge('db.client.connection.count', '{connection}', 'Open database connections by state'),
                    $meter->createObservableGauge('db.client.connection.max', '{connection}', 'Maximum open database connections'),
                    $meter->createObservableGauge('db.client.connection.pending_requests', '{request}', 'Requests waiting for a database connection'),
                );
            }
        } catch (Throwable) {
            // An instrument that cannot be created is left out; the rest stays.
        }

        if ($this->lag !== null) {
            $this->pendingArm = $metrics->whenLoopDriven(static function () use ($owner): void {
                $observer = $owner->get();

                if ($observer !== null) {
                    $observer->pendingArm = null;
                    $observer->startTimer();
                }
            });
        }
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->cancelTimer();

        foreach ($this->callbacks as $callback) {
            try {
                $callback->detach();
            } catch (Throwable) {
                // Detaching is best effort.
            }
        }

        $this->callbacks = [];
        $this->lag = null;
    }

    /**
     * Whether the fledge-fiber release in use has the pool registry; without
     * it the pool instruments are not created.
     */
    public static function poolRegistryAvailable(): bool
    {
        return interface_exists(PoolStats::class) && class_exists(PoolStatsRegistry::class);
    }

    /**
     * The loop group reports only while its lag timer is armed in a
     * loop-driven process.
     */
    private function loopActive(): bool
    {
        return ! $this->stopped && $this->timer !== null && $this->metrics !== null && $this->metrics->loopDriven();
    }

    private function observeWatchers(ObserverInterface $watchers): void
    {
        if ($this->guard === null || ! $this->loopActive()) {
            return;
        }

        try {
            $counts = array_fill_keys(array_map(static fn (CallbackType $type): string => strtolower($type->name), CallbackType::cases()), 0);

            foreach (Loop::getIdentifiers() as $identifier) {
                try {
                    $counts[strtolower(Loop::getType($identifier)->name)]++;
                } catch (Throwable) {
                    // Cancelled between the two calls.
                }
            }

            foreach ($counts as $type => $count) {
                $watchers->observe($count, $this->guard->attributes('fledge.loop.watchers', [self::ATTRIBUTE_WATCHER_TYPE => $type]));
            }
        } catch (Throwable) {
            // Never into the collect.
        }
    }

    private function observeMemory(ObserverInterface $usage, ObserverInterface $peak): void
    {
        if ($this->stopped) {
            return;
        }

        try {
            $usage->observe(memory_get_usage(true));
            $peak->observe(memory_get_peak_usage(true));
        } catch (Throwable) {
            // Never into the collect.
        }
    }

    private function observeGc(ObserverInterface $runs, ObserverInterface $collected, ObserverInterface $duration): void
    {
        if ($this->stopped) {
            return;
        }

        try {
            $status = self::gcStatus();

            $runs->observe((int) ($status['runs'] ?? 0));
            $collected->observe((int) ($status['collected'] ?? 0));

            // Seconds as a float since PHP 8.3; absent on older builds.
            if (isset($status['collector_time'])) {
                $duration->observe((float) $status['collector_time']);
            }
        } catch (Throwable) {
            // Never into the collect.
        }
    }

    /**
     * The optional keys differ per PHP build, so callers treat them as such.
     *
     * @return array<string, mixed>
     */
    private static function gcStatus(): array
    {
        return gc_status();
    }

    private function observePools(ObserverInterface $count, ObserverInterface $max, ObserverInterface $pending): void
    {
        if ($this->stopped || $this->guard === null || ! self::poolRegistryAvailable()) {
            return;
        }

        try {
            /** @var array<string, array{total: int, idle: int, max: int, waiting: int}> $sums */
            $sums = [];

            foreach (PoolStatsRegistry::all() as $entry) {
                $pool = $entry['pool'];
                $sums[$entry['name']] ??= ['total' => 0, 'idle' => 0, 'max' => 0, 'waiting' => 0];
                $sums[$entry['name']]['total'] += $pool->count();
                $sums[$entry['name']]['idle'] += $pool->getIdleCount();
                $sums[$entry['name']]['max'] += $pool->getMaxSize();
                $sums[$entry['name']]['waiting'] += $pool->getWaitingCount();
            }

            foreach ($sums as $name => $sum) {
                $name = (string) $name;
                $named = [self::ATTRIBUTE_POOL_NAME => $name];
                $idle = min($sum['idle'], $sum['total']);

                $count->observe($idle, $this->guard->attributes('db.client.connection.count', $named + [self::ATTRIBUTE_POOL_STATE => 'idle']));
                $count->observe($sum['total'] - $idle, $this->guard->attributes('db.client.connection.count', $named + [self::ATTRIBUTE_POOL_STATE => 'used']));
                $max->observe($sum['max'], $this->guard->attributes('db.client.connection.max', $named));
                $pending->observe($sum['waiting'], $this->guard->attributes('db.client.connection.pending_requests', $named));
            }
        } catch (Throwable) {
            // Never into the collect.
        }
    }

    /**
     * Record the drift of one timer firing. `$now` and the expected time are
     * monotonic seconds.
     */
    private function tick(): void
    {
        if (! $this->loopActive()) {
            $this->cancelTimer();

            return;
        }

        $now = hrtime(true) / 1_000_000_000;

        if ($this->expected !== null) {
            try {
                $this->lag?->record(max(0.0, $now - $this->expected));
            } catch (Throwable) {
                // Recording must never break the loop.
            }
        }

        $this->expected = $now + $this->lagPeriod;
    }

    /**
     * Arm the lag timer; only inside a fiber of the running loop.
     */
    private function startTimer(): void
    {
        if ($this->stopped || $this->timer !== null || $this->lag === null || \Fiber::getCurrent() === null) {
            return;
        }

        $owner = WeakReference::create($this);

        try {
            $this->expected = hrtime(true) / 1_000_000_000 + $this->lagPeriod;
            $this->timer = Loop::unreference(Loop::repeat($this->lagPeriod, static function () use ($owner): void {
                $owner->get()?->tick();
            }));
        } catch (Throwable) {
            $this->timer = null;
        }
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
}
