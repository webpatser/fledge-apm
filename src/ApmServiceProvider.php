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
use Fledge\Loop;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Behavior\Internal\Logging;
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

        Logging::setLogWriter(new ThrottledLogWriter(
            static fn (): LoggerInterface => $app->make('log'),
            $breaker,
            max(1.0, (float) ($config['breaker']['seconds'] ?? 30)),
        ));
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
