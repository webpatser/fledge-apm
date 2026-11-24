<?php

declare(strict_types=1);

namespace Fledge\Apm;

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Export\ExportCircuitBreaker;
use Fledge\Apm\Export\ThrottledLogWriter;
use Fledge\Apm\Instrumentation\CacheInstrumentation;
use Fledge\Apm\Instrumentation\ConsoleInstrumentation;
use Fledge\Apm\Instrumentation\DatabaseInstrumentation;
use Fledge\Apm\Instrumentation\ExceptionInstrumentation;
use Fledge\Apm\Instrumentation\HttpClientInstrumentation;
use Fledge\Apm\Instrumentation\HttpInstrumentation;
use Fledge\Apm\Instrumentation\Instrumentation;
use Fledge\Apm\Instrumentation\LivewireInstrumentation;
use Fledge\Apm\Instrumentation\QueueInstrumentation;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Metrics\QueueBacklogObserver;
use Fledge\Apm\Metrics\RuntimeObserver;
use Fledge\Loop;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApmServiceProvider extends ServiceProvider
{
    /**
     * Instrumentations by config key (`apm.instrumentation.<key>`). A class
     * that does not exist yet is skipped, so each lands independently.
     *
     * @var array<string, class-string<Instrumentation>>
     */
    public const array INSTRUMENTATIONS = [
        'http' => HttpInstrumentation::class,
        'queue' => QueueInstrumentation::class,
        'console' => ConsoleInstrumentation::class,
        'database' => DatabaseInstrumentation::class,
        'cache' => CacheInstrumentation::class,
        'http_client' => HttpClientInstrumentation::class,
        'livewire' => LivewireInstrumentation::class,
        'exceptions' => ExceptionInstrumentation::class,
    ];

    /** Octane's per-request end event; Octane is optional. */
    private const string OCTANE_REQUEST_TERMINATED = 'Laravel\\Octane\\Events\\RequestTerminated';

    /** Octane's per-request start event, before the app handles the request. */
    private const string OCTANE_REQUEST_RECEIVED = 'Laravel\\Octane\\Events\\RequestReceived';

    /** Octane's worker shutdown event (Swoole, RoadRunner and FrankenPHP). */
    private const string OCTANE_WORKER_STOPPING = 'Laravel\\Octane\\Events\\WorkerStopping';

    /** The missing-propagation warning is logged once per process. */
    private static bool $warnedPropagation = false;

    /** The one idle flush timer of this process, and the Tracer it flushes. */
    private static ?string $idleFlushTimer = null;

    private static ?Tracer $idleFlushTracer = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/apm.php', 'apm');

        $this->app->singleton(FiberContextStorage::class);

        $this->app->singleton(ExportCircuitBreaker::class, static function ($app): ExportCircuitBreaker {
            $config = (array) $app['config']->get('apm', []);

            return ExportCircuitBreaker::fromConfig(
                (array) ($config['breaker'] ?? []),
                TracerFactory::tracesEndpoint((array) ($config['otlp'] ?? [])),
                static fn (?string $store): Repository => $app->make('cache')->store($store),
            );
        });

        $this->app->singleton(Tracer::class, static function ($app): Tracer {
            $config = (array) $app['config']->get('apm', []);

            return TracerFactory::make(
                $config,
                $app->make(FiberContextStorage::class),
                breaker: ($config['enabled'] ?? false) && self::breakerEnabled($config) ? $app->make(ExportCircuitBreaker::class) : null,
            );
        });

        $this->app->singleton(Propagation::class);

        $this->app->singleton(Metrics::class, static function ($app): Metrics {
            $config = (array) $app['config']->get('apm', []);

            return new Metrics(
                $config,
                breaker: ($config['enabled'] ?? false) && self::breakerEnabled($config) ? $app->make(ExportCircuitBreaker::class) : null,
                log: $app->bound(ThrottledLogWriter::class) ? $app->make(ThrottledLogWriter::class) : null,
                storage: $app->make(FiberContextStorage::class),
            );
        });

        $this->registerMeterProvider();
    }

    /**
     * The binding torque, resonate and other packages use as their guard: it
     * exists only while APM and metrics are enabled. It resolves to the real
     * MeterProvider in a process that records metrics (CLI, or PHP-FPM with
     * metrics.fpm) and to a no-op provider otherwise; resolving never throws.
     * Exporting still waits for long-running detection (see Metrics).
     */
    private function registerMeterProvider(): void
    {
        $config = (array) $this->app['config']->get('apm', []);

        if (! ($config['enabled'] ?? false) || ! ($config['metrics']['enabled'] ?? false)) {
            return;
        }

        $this->app->bind(MeterProviderInterface::class, static function ($app): MeterProviderInterface {
            try {
                return $app->make(Metrics::class)->provider();
            } catch (Throwable) {
                return new NoopMeterProvider;
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/apm.php' => $this->app->configPath('apm.php'),
            ], 'apm-config');
        }

        if (! $this->app['config']->get('apm.enabled')) {
            return;
        }

        $tracer = $this->app->make(Tracer::class);

        $this->registerLogging();

        if (! $this->app->make(Propagation::class)->register()) {
            $this->warnMissingPropagation();
        }

        $this->registerFlushing($tracer);
        $this->registerMetrics();
        $this->registerContextResets($tracer);
        $this->registerInstrumentations($tracer);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function breakerEnabled(array $config): bool
    {
        return (bool) ($config['breaker']['enabled'] ?? true);
    }

    /**
     * Send the SDK's internal log lines ("Export failure") to Laravel's
     * logger, each at most once per breaker window (shared across workers
     * when the breaker has a shared store).
     */
    private function registerLogging(): void
    {
        $app = $this->app;
        $config = (array) $app['config']->get('apm', []);
        $breaker = self::breakerEnabled($config) ? $app->make(ExportCircuitBreaker::class) : null;

        $writer = new ThrottledLogWriter(
            static fn (): LoggerInterface => $app->make('log'),
            $breaker,
            max(1.0, (float) ($config['breaker']['seconds'] ?? 30)),
        );

        // Also the writer for metric cardinality overflow lines.
        $app->instance(ThrottledLogWriter::class, $writer);

        Logging::setLogWriter($writer);
    }

    private function warnMissingPropagation(): void
    {
        if (self::$warnedPropagation) {
            return;
        }

        self::$warnedPropagation = true;

        try {
            // PHP-FPM resets statics per request; the shared breaker store
            // keeps it to one line per hour across workers.
            if (
                self::breakerEnabled((array) $this->app['config']->get('apm', []))
                && ! $this->app->make(ExportCircuitBreaker::class)->firstInWindow('propagation-warning', 3600.0)
            ) {
                return;
            }

            $this->app->make('log')->warning(
                'fledge-apm: parallel fibers started with Fledge\\Async\\async() will not inherit the trace context, '
                .'because Fledge\\Async\\FiberContext is missing. Upgrade webpatser/fledge-fiber to 13.36.0.2 or later.',
            );
        } catch (Throwable) {
            // A missing logger must not stop the app from booting.
        }
    }

    /**
     * HTTP requests and artisan commands flush after the response was sent
     * (terminating callbacks). Queue workers flush on an interval from the
     * worker loop and once more when the worker stops.
     *
     * Only the final WorkerStopping flush retries; the others make one
     * attempt per chunk within the flush budget, so a down intake never
     * keeps a worker from serving.
     *
     * Under Octane the terminating callbacks run in the worker before it
     * takes the next request, so an export there holds the worker. Once an
     * Octane request was seen, the terminating flush only runs when a full
     * export batch is buffered or worker_flush_interval has passed, and
     * Octane's WorkerStopping flushes the rest.
     *
     * When the Fledge event loop runs in the process (the Fledge server, torque
     * workers), one unreferenced repeat timer also flushes in the background
     * every worker_flush_interval while spans are buffered, so an idle worker
     * does not hold them. Under Swoole, RoadRunner and FrankenPHP no loop
     * runs between requests: an idle Octane worker holds its spans until its
     * next request or WorkerStopping.
     *
     * A worker that dies without WorkerStopping (kill -9, a reload past its
     * kill timeout) loses everything buffered since the last flush: up to a
     * batch minus one plus the last request's spans, capped by
     * max_queue_size.
     */
    private function registerFlushing(Tracer $tracer): void
    {
        $config = $this->app['config'];
        $interval = max(1, (int) $config->get('apm.batch.worker_flush_interval', 5));
        $batchSize = max(1, (int) $config->get('apm.batch.max_export_batch_size', 128));
        $intervalNanos = $interval * 1_000_000_000;
        $terminating = new class
        {
            public bool $octane = false;

            public int $lastFlush = 0;
        };
        $terminating->lastFlush = hrtime(true);

        self::registerIdleFlushTimer($tracer, $interval);

        $this->app->terminating(static function () use ($tracer, $interval, $batchSize, $intervalNanos, $terminating): void {
            // The loop may start after boot (first awaited I/O of a request).
            self::registerIdleFlushTimer($tracer, $interval);

            if (
                $terminating->octane
                && ($tracer->bufferedSpans() ?? $batchSize) < $batchSize
                && hrtime(true) - $terminating->lastFlush < $intervalNanos
            ) {
                return;
            }

            $terminating->lastFlush = hrtime(true);
            $tracer->flushInBackground();
        });

        $lastFlush = hrtime(true);

        $events = $this->app->make(Dispatcher::class);

        if (class_exists(self::OCTANE_REQUEST_RECEIVED)) {
            $events->listen(self::OCTANE_REQUEST_RECEIVED, static function () use ($terminating): void {
                $terminating->octane = true;
            });
        }

        if (class_exists(self::OCTANE_WORKER_STOPPING)) {
            $events->listen(self::OCTANE_WORKER_STOPPING, static function () use ($tracer): void {
                $tracer->flushInBackground(retry: true);
            });
        }

        $events->listen(Looping::class, static function () use ($tracer, $interval, &$lastFlush): void {
            if (hrtime(true) - $lastFlush < $interval * 1_000_000_000) {
                return;
            }

            $lastFlush = hrtime(true);
            $tracer->flushInBackground();
        });

        $events->listen(WorkerStopping::class, static function () use ($tracer): void {
            $tracer->flushInBackground(retry: true);
        });
    }

    /**
     * One unreferenced repeat timer per process, only while the Fledge event
     * loop runs; never starts a loop and never keeps one alive. Each tick
     * costs one count when nothing is buffered, and otherwise starts a
     * background flush that nothing waits for. A provider booted again for
     * a new Tracer (tests) replaces the timer.
     */
    private static function registerIdleFlushTimer(Tracer $tracer, int $interval): void
    {
        if (self::$idleFlushTracer === $tracer || ! Loop::getDriver()->isRunning()) {
            return;
        }

        if (self::$idleFlushTimer !== null) {
            Loop::cancel(self::$idleFlushTimer);
        }

        self::$idleFlushTracer = $tracer;
        self::$idleFlushTimer = Loop::unreference(Loop::repeat((float) $interval, static function () use ($tracer): void {
            if (($tracer->bufferedSpans() ?? 1) > 0) {
                $tracer->flushInBackground(await: false);
            }
        }));
    }

    /**
     * Metrics cadence (see Metrics for the process model). Nothing is wired
     * when metrics are off or the process records none (PHP-FPM without
     * metrics.fpm), so traces behave exactly as before.
     *
     * - Long-running detection: Looping and WorkerStarting (queue workers),
     *   Octane's RequestReceived (under any SAPI, FrankenPHP included),
     *   Metrics::markLongRunning(), or surviving one interval on the collect
     *   timer.
     * - Cadence: the unreferenced collect timer, armed only inside a fiber of
     *   the running Fledge event loop (or on the next loop turn for a marked
     *   process), plus a collect on Looping once an interval has passed
     *   (awaited from {main}, background inside a fiber). Looping or
     *   RequestReceived fired outside any fiber marks the process
     *   event-driven: no loop timers there (see Metrics::hostEvent()).
     * - Final flush on WorkerStopping (queue and Octane) and at shutdown.
     * - metrics.fpm: in a per-request (non-CLI) process not marked
     *   long-running, each request's deltas are exported from the
     *   terminating callback, after the response was sent.
     */
    private function registerMetrics(): void
    {
        $metrics = $this->app->make(Metrics::class);

        if (! $metrics->recording()) {
            return;
        }

        $backlog = $this->registerQueueBacklog($metrics);
        $runtime = $this->registerRuntime($metrics);

        $metrics->startTimer();

        $events = $this->app->make(Dispatcher::class);

        // Must return void: a Looping listener returning false pauses the worker.
        // Looping outside any fiber marks a stock worker (no loop timers);
        // torque fires it from its reader fibers. The backlog sample runs
        // after detection (which may create the observer) and before the
        // collect, so a due collect exports the numbers of this Looping.
        $events->listen(Looping::class, static function () use ($metrics, $backlog): void {
            $metrics->hostEvent();
            $metrics->detect(Metrics::ROLE_QUEUE);
            $backlog->observer?->sampleIfDue();
            $metrics->collectIfDue();
        });

        $events->listen(WorkerStarting::class, static function () use ($metrics): void {
            $metrics->detect(Metrics::ROLE_QUEUE);
        });

        $events->listen(WorkerStopping::class, static function () use ($metrics, $backlog, $runtime): void {
            $metrics->finish();
            $backlog->observer?->stop();
            $runtime->observer?->stop();
        });

        if (class_exists(self::OCTANE_REQUEST_RECEIVED)) {
            $events->listen(self::OCTANE_REQUEST_RECEIVED, static function () use ($metrics): void {
                $metrics->hostEvent();
                $metrics->detectOctane();
            });
        }

        if (class_exists(self::OCTANE_WORKER_STOPPING)) {
            $events->listen(self::OCTANE_WORKER_STOPPING, static function () use ($metrics, $backlog, $runtime): void {
                $metrics->finish();
                $backlog->observer?->stop();
                $runtime->observer?->stop();
            });
        }

        $this->app->terminating(static function () use ($metrics, $backlog): void {
            if ($metrics->perRequest()) {
                $metrics->collect();

                return;
            }

            // The loop may start after boot; an Octane worker collects
            // between requests once an interval has passed. Short artisan
            // commands are not long-running: all three calls do nothing.
            $backlog->observer?->sampleIfDue();
            $metrics->startTimer();
            $metrics->collectIfDue();
        });
    }

    /**
     * The queue backlog gauges (see QueueBacklogObserver), behind
     * `metrics.groups.queue_backlog` and only when
     * `metrics.queue_backlog.queues` lists at least one queue. The observer
     * is built, bound and started only once the process is detected as
     * long-running, so a short artisan command or a per-request SAPI (even
     * with `metrics.fpm` on) never queries a queue or touches the lock.
     *
     * @return object{observer: ?QueueBacklogObserver} filled at detection
     */
    private function registerQueueBacklog(Metrics $metrics): object
    {
        $backlog = new class
        {
            public ?QueueBacklogObserver $observer = null;
        };

        $app = $this->app;
        $config = (array) $app['config']->get('apm', []);
        $queues = QueueBacklogObserver::configuredQueues($config);

        if ($queues === []) {
            return $backlog;
        }

        $metrics->whenLongRunning(static function () use ($app, $config, $queues, $metrics, $backlog): void {
            // A process that drives its own collects (torque's master) never
            // reports the backlog. When collectDue() only follows, the
            // observer stops itself then (see QueueBacklogObserver::start()).
            if ($metrics->selfDriven()) {
                return;
            }

            $lockStore = $config['metrics']['queue_backlog']['lock_store'] ?? null;

            $backlog->observer = new QueueBacklogObserver(
                $app->make('queue'),
                $queues,
                static fn (): Repository => $app->make('cache')->store(is_string($lockStore) && $lockStore !== '' ? $lockStore : null),
                QueueBacklogObserver::lockKeyFor($config),
                $metrics->interval(),
                $app->bound(ThrottledLogWriter::class) ? $app->make(ThrottledLogWriter::class) : null,
                $app->make(FiberContextStorage::class),
            );

            $app->instance(QueueBacklogObserver::class, $backlog->observer);
            $backlog->observer->start($metrics);
        });

        return $backlog;
    }

    /**
     * The loop, runtime and database pool instruments (see RuntimeObserver),
     * each behind its own `metrics.groups.*` toggle. Built, bound and
     * started only once the process is detected as long-running.
     *
     * @return object{observer: ?RuntimeObserver} filled at detection
     */
    private function registerRuntime(Metrics $metrics): object
    {
        $runtime = new class
        {
            public ?RuntimeObserver $observer = null;
        };

        $app = $this->app;
        $groups = RuntimeObserver::groups((array) $app['config']->get('apm', []));

        if (! in_array(true, $groups, true)) {
            return $runtime;
        }

        $metrics->whenLongRunning(static function () use ($app, $groups, $metrics, $runtime): void {
            $runtime->observer = new RuntimeObserver($groups['loop'], $groups['runtime'], $groups['database_pool']);

            $app->instance(RuntimeObserver::class, $runtime->observer);
            $runtime->observer->start($metrics);
        });

        return $runtime;
    }

    /**
     * Clear scopes leaked on the main context between units of work, before
     * the next job (queue Looping) or request (Octane) starts. Looping fires
     * between jobs, never during one, so no in-flight job span is cut off.
     */
    private function registerContextResets(Tracer $tracer): void
    {
        $storage = $tracer->storage();
        $events = $this->app->make(Dispatcher::class);

        // Must return void: a Looping listener returning false pauses the worker.
        $reset = static function () use ($storage): void {
            $storage->reset();
        };

        $events->listen(Looping::class, $reset);

        if (class_exists(self::OCTANE_REQUEST_TERMINATED)) {
            $events->listen(self::OCTANE_REQUEST_TERMINATED, $reset);
        }
    }

    private function registerInstrumentations(Tracer $tracer): void
    {
        $config = $this->app['config'];

        foreach (self::INSTRUMENTATIONS as $key => $class) {
            if (! $config->get("apm.instrumentation.{$key}", true) || ! class_exists($class)) {
                continue;
            }

            $this->app->make($class)->register($this->app, $tracer);
        }
    }
}
