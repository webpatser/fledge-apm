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

    /** The missing-propagation warning is logged once per process. */
    private static bool $warnedPropagation = false;

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
     * Not covered yet: a long-running Fledge server process that never
     * terminates the app per request would need a Revolt repeat timer.
     */
    private function registerFlushing(Tracer $tracer): void
    {
        $this->app->terminating(static fn () => $tracer->flushInBackground());

        $interval = max(1, (int) $this->app['config']->get('apm.batch.worker_flush_interval', 5));
        $lastFlush = hrtime(true);

        $events = $this->app->make(Dispatcher::class);

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
