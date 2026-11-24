<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\TracerFactory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

use function Fledge\Async\delay;

/**
 * Register and boot a fresh provider with APM and metrics enabled, whose
 * Metrics instance exports into the returned in-memory exporter.
 *
 * @param  array<string, mixed>  $metricsConfig
 * @return array{Metrics, InMemoryExporter}
 */
function bootMetricsApm(array $metricsConfig = [], string $sapi = 'cli'): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.exporter', 'memory');
    config()->set('apm.breaker.enabled', false);
    config()->set('apm.instrumentation', array_fill_keys(array_keys(ApmServiceProvider::INSTRUMENTATIONS), false));
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true], $metricsConfig));

    $provider = new ApmServiceProvider(app());
    $provider->register();

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    app()->instance(Metrics::class, new Metrics(config('apm'), $exporter, storage: app(FiberContextStorage::class), sapi: $sapi));

    $provider->boot();

    return [app(Metrics::class), $exporter];
}

function recordTestCount(int $value = 1): void
{
    Metrics::meter('tests')->createCounter('fledge.test.count')->add($value);
}

/**
 * @param  list<Metric>  $metrics
 */
function exportedCount(array $metrics): int
{
    $sum = 0;

    foreach ($metrics as $metric) {
        foreach ($metric->name === 'fledge.test.count' ? $metric->data->dataPoints : [] as $point) {
            $sum += $point->value;
        }
    }

    return $sum;
}

it('binds the MeterProvider only while APM and metrics are enabled', function () {
    expect(app()->bound(MeterProviderInterface::class))->toBeFalse();

    config()->set('apm.enabled', true);
    (new ApmServiceProvider(app()))->register();

    expect(app()->bound(MeterProviderInterface::class))->toBeFalse();

    bootMetricsApm();

    expect(app()->bound(MeterProviderInterface::class))->toBeTrue()
        ->and(app(MeterProviderInterface::class))->toBeInstanceOf(MeterProvider::class)
        ->and(app(MeterProviderInterface::class))->toBe(app(Metrics::class)->provider());

    bootMetricsApm(sapi: 'fpm-fcgi');

    expect(app(MeterProviderInterface::class))->toBeInstanceOf(NoopMeterProvider::class);
});

it('exports nothing from a short-lived artisan command', function () {
    [$metrics, $exporter] = bootMetricsApm();

    recordTestCount();
    app()->terminate();

    expect($metrics->longRunning())->toBeFalse()
        ->and($exporter->collect())->toBe([]);
});

it('starts exporting after Looping, on the next collect, as a queue process', function () {
    [$metrics, $exporter] = bootMetricsApm(['interval' => 1]);

    recordTestCount(2);
    app('events')->dispatch(new Looping('sync', 'default'));

    expect($metrics->longRunning())->toBeTrue()
        ->and($exporter->collect())->toBe([]); // not due yet

    usleep(1_050_000);
    $result = app('events')->until(new Looping('sync', 'default'));
    $metrics->wait();

    $exported = $exporter->collect();

    expect($result)->not->toBeFalse()
        ->and(exportedCount($exported))->toBe(2)
        ->and($exported[0]->resource->getAttributes()->get(TracerFactory::PROCESS_ROLE))->toBe(Metrics::ROLE_QUEUE);

    $metrics->stop();
});

it('detects a worker on WorkerStarting and a marked process through markLongRunning()', function () {
    [$worker] = bootMetricsApm();
    event(new WorkerStarting('sync', 'default', null));

    expect($worker->longRunning())->toBeTrue()
        ->and($worker->role())->toBe(Metrics::ROLE_QUEUE);

    [$marked, $exporter] = bootMetricsApm();
    recordTestCount(4);
    Metrics::markLongRunning('torque');

    expect($marked->collect())->toBeTrue()
        ->and(exportedCount($exporter->collect()))->toBe(4)
        ->and($exporter->collect()[0]->resource->getAttributes()->get(TracerFactory::PROCESS_ROLE))->toBe('torque');

    $worker->stop();
    $marked->stop();
});

it('flushes once more when the worker stops', function () {
    [$metrics, $exporter] = bootMetricsApm();

    app('events')->dispatch(new Looping('sync', 'default'));
    recordTestCount(3);
    event(new WorkerStopping(0));

    expect(exportedCount($exporter->collect()))->toBe(3)
        ->and($metrics->collect())->toBeFalse();
});

it('exports per request under PHP-FPM only with metrics.fpm on', function () {
    [$off, $offExporter] = bootMetricsApm(['fpm' => false], 'fpm-fcgi');

    recordTestCount();
    app()->terminate();

    expect($off->recording())->toBeFalse()
        ->and(Metrics::enabled())->toBeFalse()
        ->and($offExporter->collect())->toBe([]);

    [$on, $onExporter] = bootMetricsApm(['fpm' => true], 'fpm-fcgi');

    recordTestCount(5);
    app()->terminate();

    expect($on->perRequest())->toBeTrue()
        ->and($on->longRunning())->toBeFalse()
        ->and(exportedCount($onExporter->collect()))->toBe(5)
        ->and($onExporter->collect()[0]->resource->getAttributes()->get(TracerFactory::PROCESS_ROLE))->toBe(TracerFactory::defaultRole());
});

it('treats an Octane worker under a non-CLI SAPI as long-running, not per request', function () {
    // Octane is not installed here; detectOctane() is what its RequestReceived listener calls.
    [$metrics, $exporter] = bootMetricsApm(['fpm' => true], 'frankenphp');

    Metrics::markLongRunning('torque'); // ignored outside CLI

    expect($metrics->longRunning())->toBeFalse()
        ->and($metrics->perRequest())->toBeTrue();

    $metrics->detectOctane();
    recordTestCount(7);
    app()->terminate();

    expect($metrics->longRunning())->toBeTrue()
        ->and($metrics->perRequest())->toBeFalse()
        ->and($metrics->role())->toBe(Metrics::ROLE_OCTANE)
        ->and($exporter->collect())->toBe([]); // no per-request export, the interval is not due

    expect($metrics->finish())->toBeTrue();

    $exported = $exporter->collect();

    expect(exportedCount($exported))->toBe(7)
        ->and($exported[0]->resource->getAttributes()->get(TracerFactory::PROCESS_ROLE))->toBe(Metrics::ROLE_OCTANE);
});

it('marks a process that survives one interval on the loop timer as long-running', function () {
    delay(0); // the event loop now runs, as in the Fledge server and torque workers

    [$metrics, $exporter] = bootMetricsApm(['interval' => 1]);
    recordTestCount(6);

    expect($metrics->longRunning())->toBeFalse();

    delay(1.2);
    $metrics->wait();

    expect($metrics->longRunning())->toBeTrue()
        ->and(exportedCount($exporter->collect()))->toBe(6);

    $metrics->stop();
});

it('wires nothing for metrics when they are off', function () {
    config()->set('apm.enabled', true);
    config()->set('apm.exporter', 'memory');
    config()->set('apm.instrumentation', array_fill_keys(array_keys(ApmServiceProvider::INSTRUMENTATIONS), false));

    $provider = new ApmServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(Event::hasListeners(WorkerStarting::class))->toBeFalse()
        ->and(app()->bound(MeterProviderInterface::class))->toBeFalse()
        ->and(Metrics::enabled())->toBeFalse();
});
