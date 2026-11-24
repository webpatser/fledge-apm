<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

use Closure;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\ExportCircuitBreaker;
use Fledge\Apm\TracerFactory;
use Fledge\Async\Future;
use Fledge\Loop;
use Illuminate\Container\Container;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use Psr\Log\LogLevel;
use Throwable;
use WeakReference;

use function Fledge\Async\async;

/**
 * The metrics surface of fledge-apm: one instance per application (a
 * container singleton), reached through the static methods below.
 *
 *     $histogram = Metrics::meter('my-package')->createHistogram('x.duration', 's', null, [...]);
 *     $histogram->record($seconds, Metrics::cardinality()->attributes('x.duration', $attributes));
 *     Metrics::markLongRunning('torque');
 *     Metrics::collectDue(); // only a process without a continuously running loop
 *
 * Callers never branch: meter() returns a no-op meter when APM or metrics
 * are off, when there is no application container, and under a per-request
 * (non-CLI) SAPI such as PHP-FPM unless `metrics.fpm` is on.
 *
 * Process model. Only two kinds of process export:
 * - A long-running process: a CLI process that saw Looping or
 *   WorkerStarting (queue workers), markLongRunning(), or survived one
 *   `metrics.interval` on the event loop timer; or an Octane worker under
 *   any SAPI, through RequestReceived (Octane on FrankenPHP is not CLI and
 *   records only with `metrics.fpm` on). It collects every interval and
 *   flushes once more on WorkerStopping and at shutdown. The interval is
 *   driven by the loop timer (see startTimer()), by Looping in a queue
 *   worker, or by the process itself through collectDue().
 * - A non-CLI request with `metrics.fpm` on, not marked long-running: the
 *   request's deltas go out from the terminating callback, after the
 *   response was sent.
 * Everything else (short artisan commands, PHP-FPM by default) exports
 * nothing and never waits at exit.
 *
 * Before detection. Under CLI the real MeterProvider is built at the first
 * meter() call, because instruments are created at boot or on first use,
 * before the long-running signal arrives, and an instrument bound to a no-op
 * meter would stay no-op for the life of the process. Values recorded
 * before detection are therefore aggregated and go out with the first
 * collect after detection (with delta temporality, as one data point
 * starting at the first recording). A process that is never detected
 * discards them at exit. Only the export cadence depends on detection.
 *
 * The resource is fixed when the provider is built, which may be before
 * the role is known; exports carry a resource rebuilt for the current
 * `fledge.process.role` and pid (forked workers get their own instance id).
 *
 * Each export runs in a fresh fiber at the root context, so it is never part
 * of a trace, and two collects never overlap (a collect while one is in
 * flight is skipped; the final flush waits for it).
 */
final class Metrics
{
    /** `fledge.process.role` of a process detected through queue worker events. */
    public const string ROLE_QUEUE = 'queue';

    /** `fledge.process.role` of a process detected through Octane's RequestReceived. */
    public const string ROLE_OCTANE = 'octane';

    /** The one collect timer of this process and the instance it serves. */
    private static ?string $timer = null;

    /** @var WeakReference<self>|null */
    private static ?WeakReference $timerOwner = null;

    private static bool $shutdownRegistered = false;

    /** @var WeakReference<self>|null the instance the shutdown flush serves */
    private static ?WeakReference $shutdownTarget = null;

    private readonly bool $cli;

    private readonly bool $recording;

    private readonly bool $perRequest;

    private readonly int $interval;

    private readonly bool $resetGuardPerCollect;

    private readonly CardinalityGuard $guard;

    private ?MetricsFactory $factory = null;

    private bool $built = false;

    private ?ProcessResourceExporter $resourceExporter = null;

    private ?string $role = null;

    private ?string $stampedRole = null;

    private int|false $stampedPid = false;

    private bool $longRunning = false;

    /** Marked through markLongRunning() (or detect() with an explicit role). */
    private bool $marked = false;

    /** The Loop::defer() id that arms the timer on the first loop turn. */
    private ?string $pendingTimer = null;

    private int|false $pendingPid = false;

    /** @var int|false|null the pid that drives its own collects through collectDue() */
    private int|false|null $selfDrivenPid = null;

    /** Looping or Octane's RequestReceived fired outside any fiber: the host drives the cadence. */
    private bool $eventDriven = false;

    /** @var list<Closure(): void> run once when the process turns out to be self-driven */
    private array $onSelfDriven = [];

    /** @var list<Closure(): void> run once when the process turns out to be event-driven */
    private array $onEventDriven = [];

    /** @var list<Closure(): void> whenLoopDriven() callbacks from {main} of an unmarked process, held until a detection inside a fiber */
    private array $heldLoopDriven = [];

    private readonly ?LogWriterInterface $log;

    private bool $collecting = false;

    /** @var Future<bool>|null */
    private ?Future $inFlight = null;

    private bool $finished = false;

    private int $lastCollect;

    private ?MeterProviderInterface $noop = null;

    /** @var list<Closure(): void> run once at long-running detection */
    private array $onLongRunning = [];

    /**
     * @param  array<string, mixed>  $config  the `apm` config array
     * @param  MetricExporterInterface|null  $exporter  replaces the configured exporter (tests)
     * @param  ExportCircuitBreaker|null  $breaker  the breaker the trace path uses; null for none
     * @param  LogWriterInterface|null  $log  the throttled writer for cardinality overflow lines
     * @param  string  $sapi  PHP_SAPI (tests pass "fpm-fcgi")
     */
    public function __construct(
        private readonly array $config,
        private readonly ?MetricExporterInterface $exporter = null,
        private readonly ?ExportCircuitBreaker $breaker = null,
        ?LogWriterInterface $log = null,
        private readonly FiberContextStorage $storage = new FiberContextStorage,
        string $sapi = PHP_SAPI,
    ) {
        $metrics = (array) ($config['metrics'] ?? []);
        $enabled = (bool) ($config['enabled'] ?? false) && (bool) ($metrics['enabled'] ?? false);

        $this->cli = in_array($sapi, ['cli', 'phpdbg'], true);
        $this->perRequest = $enabled && ! $this->cli && (bool) ($metrics['fpm'] ?? false);
        $this->recording = $enabled && ($this->cli || $this->perRequest);
        $this->interval = max(1, (int) ($metrics['interval'] ?? 60));
        $this->resetGuardPerCollect = ! in_array(strtolower((string) ($metrics['temporality'] ?? 'delta')), ['cumulative', 'lowmemory'], true);
        $this->guard = new CardinalityGuard(max(1, (int) ($metrics['cardinality_limit'] ?? 200)), $log);
        $this->log = $log;
        $this->lastCollect = hrtime(true);
    }

    /**
     * A meter for `$scope` (the instrumentation name, e.g. "torque"). A no-op
     * meter when this process records no metrics.
     */
    public static function meter(string $scope): MeterInterface
    {
        try {
            return self::current()?->provider()->getMeter($scope) ?? new NoopMeter;
        } catch (Throwable) {
            return new NoopMeter;
        }
    }

    /**
     * Whether this process records metrics (APM and metrics on, and a CLI
     * process or `metrics.fpm`). True before long-running detection too.
     */
    public static function enabled(): bool
    {
        return self::current()?->recording() ?? false;
    }

    /**
     * Mark this CLI process as long-running, so it exports every interval,
     * and set `fledge.process.role` (e.g. "torque", "resonate"). Overrides a
     * role detected from queue or Octane events. Ignored outside CLI.
     *
     * The interval runs on an unreferenced Fledge event loop timer. Called
     * inside a fiber of the running loop, the timer starts now; called from
     * {main} (before Loop::run(), or between awaits), the start is queued
     * once (an unreferenced Loop::defer(), which neither starts a loop nor
     * keeps one alive) and the timer arms on the next loop turn, if a loop
     * ever turns. A process that never runs a loop gets no timer. A process
     * that calls collectDue() never gets one either (see there), and neither
     * does a stock queue worker (Looping fired outside any fiber).
     */
    public static function markLongRunning(string $role): void
    {
        self::current()?->detect($role, explicit: true);
    }

    /**
     * Collect and export now when due, for a long-running process that has
     * no continuously running event loop and drives the interval itself
     * (torque's master calls it from its maintenance tick). Never throws.
     *
     * Returns false and does nothing unless metrics are recording, the
     * process is long-running (markLongRunning() or a detection signal),
     * the final flush has not run, no collect is in flight, and at least
     * `metrics.interval` seconds passed since the last collect (or since
     * boot). Otherwise it collects at the root context, waits until the
     * export finished (bounded by the OTLP request timeouts, no retries)
     * and returns whether the export succeeded. From {main} the wait drives
     * the event loop until the export completes (starting it when it does
     * not run, as the final flush does); inside a fiber of a running loop
     * it suspends only that fiber.
     *
     * Timer opt-out: the first call in a process with metrics recording,
     * due or not and before or after markLongRunning(), cancels this
     * process's loop timer and a queued timer start, and no loop timer
     * starts for this process afterwards (forked children are not
     * affected). From then on only collectDue(), Looping and the final
     * flush export. Call it every second or so: an interval only goes out
     * on the first call after it elapsed. The queue backlog observer and the
     * loop group of the runtime observer stop in such a process (whichever
     * of markLongRunning() and collectDue() comes first): it reports neither
     * the queue backlog nor loop metrics.
     */
    public static function collectDue(): bool
    {
        try {
            return self::current()?->driveCollect() ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The cardinality guard every recording goes through. Hold it once and
     * call `attributes($instrumentName, $attributes)` per recording.
     */
    public static function cardinality(): CardinalityGuard
    {
        return self::current()?->guard() ?? new CardinalityGuard;
    }

    private static function current(): ?self
    {
        try {
            $app = Container::getInstance();

            return $app->bound(self::class) ? $app->make(self::class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function recording(): bool
    {
        return $this->recording;
    }

    /**
     * A non-CLI process with `metrics.fpm` on that was not marked
     * long-running (an Octane worker is): exports per request.
     */
    public function perRequest(): bool
    {
        return $this->perRequest && ! $this->longRunning;
    }

    public function longRunning(): bool
    {
        return $this->longRunning;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function guard(): CardinalityGuard
    {
        return $this->guard;
    }

    /**
     * The real MeterProvider (built on first use) when this process records,
     * else a no-op one. Never throws.
     */
    public function provider(): MeterProviderInterface
    {
        return $this->factory()?->provider() ?? ($this->noop ??= new NoopMeterProvider);
    }

    /**
     * Mark the process long-running. `$role` from a detection signal only
     * applies while no role is set; an explicit role always does.
     */
    public function detect(?string $role = null, bool $explicit = false): void
    {
        if ($this->cli) {
            $this->markDetected($role, $explicit);
        }
    }

    /**
     * Octane's RequestReceived: a long-lived worker under any SAPI (Octane
     * on FrankenPHP is not a CLI process). From here on it exports every
     * interval, not per request.
     */
    public function detectOctane(): void
    {
        $this->markDetected(self::ROLE_OCTANE);
    }

    private function markDetected(?string $role, bool $explicit = false): void
    {
        if (! $this->recording || $this->finished) {
            return;
        }

        if ($role !== null && $role !== '' && ($explicit || $this->role === null)) {
            $this->role = $role;
        }

        if ($explicit) {
            $this->marked = true;
        }

        if (! $this->longRunning) {
            $this->longRunning = true;
            self::registerShutdown($this);
            $this->runOnce($this->onLongRunning);
        }

        // Inside a fiber (torque's Looping or its recordWorker()) the held
        // callbacks run now; an explicit mark from {main} queues them for
        // the next loop turn.
        if ($this->heldLoopDriven !== [] && (\Fiber::getCurrent() !== null || $this->marked)) {
            $held = $this->heldLoopDriven;
            $this->heldLoopDriven = [];

            foreach ($held as $callback) {
                $this->whenLoopDriven($callback);
            }
        }

        $this->startTimer();
    }

    /**
     * Run `$callback` once this process is detected as long-running: now
     * when it already is, never when it records no metrics or never gets
     * detected (short artisan commands, per-request SAPIs). Exceptions from
     * the callback are logged through the throttled writer, never thrown.
     *
     * @param  Closure(): void  $callback
     */
    public function whenLongRunning(Closure $callback): void
    {
        if ($this->longRunning && ! $this->finished) {
            $this->run($callback);

            return;
        }

        if ($this->recording && ! $this->finished) {
            $this->onLongRunning[] = $callback;
        }
    }

    /**
     * Run `$callback` once this process turns out to drive its own collects
     * through collectDue(): now when it already does. Exceptions are logged,
     * never thrown.
     *
     * @param  Closure(): void  $callback
     */
    public function whenSelfDriven(Closure $callback): void
    {
        if ($this->selfDriven()) {
            $this->run($callback);

            return;
        }

        $this->onSelfDriven[] = $callback;
    }

    /**
     * Run `$callback` once this process turns out to be event-driven (see
     * hostEvent()): now when it already is. Observers cancel their loop
     * timers here. Exceptions are logged, never thrown.
     *
     * @param  Closure(): void  $callback
     */
    public function whenEventDriven(Closure $callback): void
    {
        if ($this->eventDriven) {
            $this->run($callback);

            return;
        }

        $this->onEventDriven[] = $callback;
    }

    /**
     * This process (this pid) drives its own collects through collectDue():
     * no loop timers, no queue backlog, no loop metrics.
     */
    public function selfDriven(): bool
    {
        return $this->selfDrivenPid !== null && $this->selfDrivenPid === getmypid();
    }

    /**
     * Looping (a stock queue worker) or Octane's RequestReceived fired
     * outside any fiber: the host's events drive the cadence, and this
     * process turns the loop only briefly, from the main context.
     */
    public function eventDriven(): bool
    {
        return $this->eventDriven;
    }

    /**
     * Called by the Looping and RequestReceived listeners. Outside any
     * fiber it marks the process event-driven, cancels a loop timer and a
     * queued timer start, drops the held whenLoopDriven() callbacks and runs
     * the whenEventDriven() ones; inside a fiber (a torque worker fires
     * Looping from its reader fibers) it does nothing.
     */
    public function hostEvent(): void
    {
        if ($this->eventDriven || \Fiber::getCurrent() !== null) {
            return;
        }

        $this->eventDriven = true;
        $this->heldLoopDriven = [];
        $this->stop();
        $this->runOnce($this->onEventDriven);
    }

    /**
     * Whether loop timers belong in this process: neither event-driven nor
     * self-driven, and the final flush has not run.
     */
    public function loopDriven(): bool
    {
        return ! $this->eventDriven && ! $this->selfDriven() && ! $this->finished;
    }

    /**
     * Run `$callback` inside a fiber of the running loop while this process
     * is loop-driven: now when a fiber is current. From {main} of a process
     * marked through markLongRunning() it runs on the next loop turn
     * through one unreferenced Loop::defer() (which neither starts a loop
     * nor keeps one alive), re-checking loopDriven() when it runs. From
     * {main} of an unmarked process it is held until markDetected() runs
     * inside a fiber, because a stock worker turns the loop from {main}
     * before its first Looping (signal watchers) and must arm nothing
     * there; hostEvent() drops it. Nothing in an event-driven or self-driven
     * process. Returns the defer id, for the caller to cancel, or null when
     * nothing was queued.
     *
     * @param  Closure(): void  $callback
     */
    public function whenLoopDriven(Closure $callback): ?string
    {
        if (! $this->loopDriven()) {
            return null;
        }

        if (\Fiber::getCurrent() !== null) {
            $this->run($callback);

            return null;
        }

        if (! $this->marked) {
            $this->heldLoopDriven[] = $callback;

            return null;
        }

        $owner = WeakReference::create($this);

        try {
            return Loop::unreference(Loop::defer(static function () use ($owner, $callback): void {
                $metrics = $owner->get();

                if ($metrics !== null && $metrics->loopDriven()) {
                    $metrics->run($callback);
                }
            }));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function run(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // A hook must never break detection; it is logged, throttled.
            try {
                $this->log?->write(LogLevel::WARNING, sprintf('fledge-apm: a metrics hook failed: %s: %s', $e::class, mb_substr($e->getMessage(), 0, 200)), []);
            } catch (Throwable) {
                // Logging must never break detection either.
            }
        }
    }

    /**
     * Empty a hook list, then run what it held.
     *
     * @param  list<Closure(): void>  $callbacks
     *
     * @param-out list<Closure(): void>  $callbacks
     */
    private function runOnce(array &$callbacks): void
    {
        $pending = $callbacks;
        $callbacks = [];

        foreach ($pending as $callback) {
            $this->run($callback);
        }
    }

    /**
     * Collect and export now, in a fresh fiber at the root context. False
     * when skipped: the process does not export (yet), nothing was ever
     * recorded, a collect is in flight, or the final flush already ran.
     *
     * With `$await` false inside a fiber the export continues in the
     * background; from {main} it is always awaited (a background fiber
     * queued there would only run at the next loop turn, and a stock queue
     * worker turns the loop only briefly, from the main context).
     */
    public function collect(bool $await = true): bool
    {
        if (! $this->exporting() || $this->finished || $this->collecting || $this->factory === null) {
            return false;
        }

        $factory = $this->factory;
        $this->collecting = true;
        $this->lastCollect = hrtime(true);
        $this->stampResource();

        try {
            $future = async(function () use ($factory): bool {
                $scope = $this->storage->attach(Context::getRoot());

                try {
                    // No suspension between the reset and the SDK's
                    // synchronous snapshot, so the guard's sets match the
                    // interval the snapshot closes.
                    if ($this->resetGuardPerCollect) {
                        $this->guard->reset();
                    }

                    return $factory->collect();
                } finally {
                    $scope->detach();
                    $this->collecting = false;
                    $this->inFlight = null;
                }
            });
        } catch (Throwable) {
            $this->collecting = false;

            return false;
        }

        // async() queues the fiber, so it has not finished yet; were it to
        // finish inline, wait() on the settled future returns at once.
        $this->inFlight = $future;

        if ($await || \Fiber::getCurrent() === null) {
            return self::settle($future);
        }

        $future->ignore();

        return true;
    }

    /**
     * collect() when at least one interval passed since the last one.
     */
    public function collectIfDue(bool $await = false): bool
    {
        if (hrtime(true) - $this->lastCollect < $this->interval * 1_000_000_000) {
            return false;
        }

        return $this->collect($await);
    }

    /**
     * collectDue(): opt this process out of the loop timer, then an awaited
     * collectIfDue() when long-running.
     */
    private function driveCollect(): bool
    {
        if (! $this->recording || $this->finished) {
            return false;
        }

        if (! $this->selfDriven()) {
            $this->selfDrivenPid = getmypid();
            $this->heldLoopDriven = [];
            $this->stop();
            $this->runOnce($this->onSelfDriven);
        }

        return $this->longRunning && $this->collectIfDue(await: true);
    }

    /**
     * Wait for an in-flight collect, if any.
     */
    public function wait(): void
    {
        if ($this->inFlight !== null) {
            self::settle($this->inFlight);
        }
    }

    /**
     * The final flush of an exporting process (WorkerStopping, shutdown):
     * waits for an in-flight collect, collects once more, closes the
     * exporter and stops the timer. Later calls and collects do nothing.
     * Bounded by the request timeouts only (the metrics transport has no
     * retries).
     */
    public function finish(): bool
    {
        if ($this->finished || ! $this->exporting()) {
            return false;
        }

        $this->wait();
        $this->finished = true;
        $this->stop();

        $factory = $this->factory;

        if ($factory === null) {
            return false;
        }

        $this->stampResource();

        try {
            return self::settle(async(function () use ($factory): bool {
                $scope = $this->storage->attach(Context::getRoot());

                try {
                    return $factory->shutdown();
                } finally {
                    $scope->detach();
                }
            }));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One unreferenced repeat timer per process at `metrics.interval`, armed
     * only inside a fiber of the running Fledge event loop: it never starts
     * a loop and never keeps one alive, so a short command exits without
     * waiting for it. The first tick marks the process long-running (it
     * survived one interval); every due tick starts a background collect. A
     * newer instance (a new application in tests) replaces the timer.
     *
     * Called from {main} by a process marked through markLongRunning(), the
     * start is queued once with an unreferenced Loop::defer(): it arms on
     * the next loop turn, and a loop with nothing else to do never runs it.
     * Nothing starts in a process that drives its own collects through
     * collectDue() or whose cadence comes from Looping or Octane events
     * fired outside any fiber (see loopDriven()).
     */
    public function startTimer(): void
    {
        if (! $this->timerAllowed() || self::$timerOwner?->get() === $this) {
            return;
        }

        if (\Fiber::getCurrent() === null) {
            $this->deferTimer();

            return;
        }

        $this->armTimer();
    }

    private function timerAllowed(): bool
    {
        return $this->recording && ($this->cli || $this->longRunning) && $this->loopDriven();
    }

    private function armTimer(): void
    {
        $this->cancelPendingTimer();

        if (self::$timer !== null) {
            Loop::cancel(self::$timer);
        }

        $owner = WeakReference::create($this);
        self::$timerOwner = $owner;
        self::$timer = Loop::unreference(Loop::repeat((float) $this->interval, static function () use ($owner): void {
            $owner->get()?->tick();
        }));
    }

    /**
     * Cancel this instance's collect timer and a queued timer start.
     */
    public function stop(): void
    {
        $this->cancelPendingTimer();

        if (self::$timer === null || self::$timerOwner?->get() !== $this) {
            return;
        }

        Loop::cancel(self::$timer);
        self::$timer = null;
        self::$timerOwner = null;
    }

    /**
     * Queue startTimer() for the first loop turn, once, in a CLI process
     * marked long-running. Detection through worker or Octane events queues
     * nothing: those processes collect on Looping or after each request.
     */
    private function deferTimer(): void
    {
        if (! $this->cli || ! $this->longRunning || ! $this->marked) {
            return;
        }

        if ($this->pendingTimer !== null) {
            if ($this->pendingPid === getmypid()) {
                return;
            }

            $this->pendingTimer = null; // inherited through fork(): the child's driver never had it
        }

        $owner = WeakReference::create($this);
        $this->pendingPid = getmypid();

        try {
            $this->pendingTimer = Loop::unreference(Loop::defer(static function () use ($owner): void {
                $metrics = $owner->get();

                if ($metrics === null) {
                    return;
                }

                $metrics->pendingTimer = null;

                try {
                    // A loop turn inside a fiber: arm here, never queue again.
                    if ($metrics->timerAllowed() && self::$timerOwner?->get() !== $metrics) {
                        $metrics->armTimer();
                    }
                } catch (Throwable) {
                    // A loop callback must not throw into the loop.
                }
            }));
        } catch (Throwable) {
            $this->pendingTimer = null;
        }
    }

    private function cancelPendingTimer(): void
    {
        if ($this->pendingTimer === null) {
            return;
        }

        try {
            Loop::cancel($this->pendingTimer);
        } catch (Throwable) {
            // Gone with the driver (a fork replaced it).
        }

        $this->pendingTimer = null;
    }

    /**
     * One timer firing: stops the timer once the process turned out to be
     * event-driven or self-driven; otherwise detects and collects when due,
     * with a tenth of an interval of slack, so a tick right after a Looping
     * or collectDue() collect does not export the gauges twice.
     */
    private function tick(): void
    {
        if (! $this->loopDriven()) {
            $this->stop();

            return;
        }

        if (! $this->longRunning) {
            $this->detect();
        }

        if (hrtime(true) - $this->lastCollect < $this->interval * 900_000_000) {
            return;
        }

        $this->collect(await: false);
    }

    private function exporting(): bool
    {
        return $this->longRunning || $this->perRequest;
    }

    private function factory(): ?MetricsFactory
    {
        if ($this->built || ! $this->recording) {
            return $this->factory;
        }

        $this->built = true;

        try {
            $this->resourceExporter = new ProcessResourceExporter($this->exporter ?? MetricsFactory::exporter($this->config));
            $this->factory = MetricsFactory::make($this->config, $this->resourceExporter, $this->breaker, $this->role);
            $this->stampedRole = $this->role;
            $this->stampedPid = getmypid();
        } catch (Throwable) {
            $this->factory = null;
        }

        return $this->factory;
    }

    /**
     * Point exports at a resource for the current role and pid when either
     * changed since the provider was built.
     */
    private function stampResource(): void
    {
        $pid = getmypid();

        if ($this->resourceExporter === null || ($this->role === $this->stampedRole && $pid === $this->stampedPid)) {
            return;
        }

        $this->stampedRole = $this->role;
        $this->stampedPid = $pid;

        try {
            $this->resourceExporter->useResource(TracerFactory::resource($this->config, $this->role));
        } catch (Throwable) {
            // Keep the resource the provider was built with.
        }
    }

    /**
     * @param  Future<bool>  $future
     */
    private static function settle(Future $future): bool
    {
        try {
            return (bool) $future->await();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One shutdown function per process, flushing the latest long-running
     * instance. Short-lived processes never register it.
     */
    private static function registerShutdown(self $metrics): void
    {
        self::$shutdownTarget = WeakReference::create($metrics);

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            self::$shutdownTarget?->get()?->finish();
        });
    }
}
