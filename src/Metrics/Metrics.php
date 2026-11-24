<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

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
 *   flushes once more on WorkerStopping and at shutdown.
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

    private bool $collecting = false;

    /** @var Future<bool>|null */
    private ?Future $inFlight = null;

    private bool $finished = false;

    private int $lastCollect;

    private ?MeterProviderInterface $noop = null;

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
     */
    public static function markLongRunning(string $role): void
    {
        self::current()?->detect($role, explicit: true);
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

        if (! $this->longRunning) {
            $this->longRunning = true;
            self::registerShutdown($this);
        }

        $this->startTimer();
    }

    /**
     * Collect and export now, in a fresh fiber at the root context. False
     * when skipped: the process does not export (yet), nothing was ever
     * recorded, a collect is in flight, or the final flush already ran.
     *
     * With `$await` false and a running event loop the export continues in
     * the background; without a running loop it is always awaited.
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

        if ($await || ! self::loopRunning()) {
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
     * One unreferenced repeat timer per process at `metrics.interval`, only
     * while the Fledge event loop runs: it never starts a loop and never
     * keeps one alive, so a short command exits without waiting for it. The
     * first tick marks the process long-running (it survived one interval);
     * every tick starts a background collect. A newer instance (a new
     * application in tests) replaces the timer.
     */
    public function startTimer(): void
    {
        if (! $this->recording || ! ($this->cli || $this->longRunning) || $this->finished || self::$timerOwner?->get() === $this || ! self::loopRunning()) {
            return;
        }

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
     * Cancel this instance's collect timer.
     */
    public function stop(): void
    {
        if (self::$timer === null || self::$timerOwner?->get() !== $this) {
            return;
        }

        Loop::cancel(self::$timer);
        self::$timer = null;
        self::$timerOwner = null;
    }

    private function tick(): void
    {
        if (! $this->longRunning) {
            $this->detect();
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

    private static function loopRunning(): bool
    {
        try {
            return Loop::getDriver()->isRunning();
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
