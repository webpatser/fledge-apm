<?php

declare(strict_types=1);

use Fledge\Apm\Metrics\CardinalityGuard;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Tests\Fixtures\Metrics\SlowMetricExporter;
use Fledge\Apm\TracerFactory;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

use function Fledge\Async\delay;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function metricsTestConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'enabled' => true,
        'exporter' => 'memory',
        'service' => ['name' => 'fledge-apm-tests'],
        'metrics' => ['enabled' => true, 'interval' => 60],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{Metrics, InMemoryExporter}
 */
function metricsRuntime(array $overrides = [], string $sapi = 'cli', ?MetricExporterInterface $exporter = null): array
{
    $memory = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());

    return [new Metrics(metricsTestConfig($overrides), $exporter ?? $memory, sapi: $sapi), $memory];
}

/**
 * Sum of all exported data point values of `$name`.
 *
 * @param  iterable<Metric>  $metrics
 */
function exportedSum(iterable $metrics, string $name): int|float
{
    $sum = 0;

    foreach ($metrics as $metric) {
        if ($metric->name === $name) {
            foreach ($metric->data->dataPoints as $point) {
                $sum += $point->value;
            }
        }
    }

    return $sum;
}

it('hands out a working no-op meter when metrics are off', function () {
    expect(config('apm.enabled'))->toBeFalse()
        ->and(Metrics::enabled())->toBeFalse()
        ->and(Metrics::meter('tests'))->toBeInstanceOf(NoopMeter::class);

    Metrics::meter('tests')->createCounter('fledge.test.count')->add(1);
    Metrics::meter('tests')->createHistogram('fledge.test.duration', 's')->record(0.1);
    Metrics::markLongRunning('torque');

    [$disabled] = metricsRuntime(['metrics' => ['enabled' => false]]);
    [$fpm] = metricsRuntime(sapi: 'fpm-fcgi');

    expect($disabled->recording())->toBeFalse()
        ->and($disabled->provider())->toBeInstanceOf(NoopMeterProvider::class)
        ->and($fpm->recording())->toBeFalse()
        ->and($fpm->provider())->toBeInstanceOf(NoopMeterProvider::class)
        ->and(Metrics::cardinality())->toBeInstanceOf(CardinalityGuard::class);
});

it('records but exports nothing from a short-lived CLI process', function () {
    [$metrics, $exporter] = metricsRuntime();

    $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count')->add(1);

    expect($metrics->provider())->toBeInstanceOf(MeterProvider::class)
        ->and($metrics->longRunning())->toBeFalse()
        ->and($metrics->collect())->toBeFalse()
        ->and($metrics->collectIfDue())->toBeFalse()
        ->and($metrics->finish())->toBeFalse()
        ->and($exporter->collect())->toBe([]);
});

it('exports values recorded before detection with the first collect after it, under the marked role', function () {
    [$metrics, $exporter] = metricsRuntime();
    $counter = $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count');

    $counter->add(3); // before detection: the provider was built with the default role

    $metrics->detect('torque', explicit: true);
    $counter->add(2);

    expect($metrics->collect())->toBeTrue();

    $exported = $exporter->collect(reset: true);

    expect(exportedSum($exported, 'fledge.test.count'))->toBe(5)
        ->and($exported[0]->resource->getAttributes()->get(TracerFactory::PROCESS_ROLE))->toBe('torque')
        ->and($exported[0]->resource->getAttributes()->get(TracerFactory::SERVICE_INSTANCE_ID))->toBe(TracerFactory::serviceInstanceId());

    $counter->add(4);
    $metrics->collect();

    expect(exportedSum($exporter->collect(reset: true), 'fledge.test.count'))->toBe(4);
});

it('keeps an explicit role over one detected from worker events', function () {
    [$metrics] = metricsRuntime();

    $metrics->detect('torque', explicit: true);
    $metrics->detect(Metrics::ROLE_QUEUE);

    expect($metrics->role())->toBe('torque');

    [$queue] = metricsRuntime();
    $queue->detect(Metrics::ROLE_QUEUE);
    $queue->detect('torque', explicit: true);

    expect($queue->role())->toBe('torque');
});

it('serves the static API from the container instance', function () {
    [$metrics, $exporter] = metricsRuntime();
    app()->instance(Metrics::class, $metrics);

    Metrics::markLongRunning('resonate');
    Metrics::meter('tests')->createCounter('fledge.test.count')->add(7);

    expect(Metrics::enabled())->toBeTrue()
        ->and(Metrics::cardinality())->toBe($metrics->guard())
        ->and($metrics->collect())->toBeTrue()
        ->and(exportedSum($exporter->collect(), 'fledge.test.count'))->toBe(7)
        ->and($exporter->collect()[0]->resource->getAttributes()->get(TracerFactory::PROCESS_ROLE))->toBe('resonate');
});

it('never runs two collects at once', function () {
    delay(0); // a running event loop, as in torque and the Fledge server

    $slow = new SlowMetricExporter(0.2);
    [$metrics] = metricsRuntime(exporter: $slow);
    $metrics->detect('torque', explicit: true);
    $counter = $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count');

    $counter->add(1);

    expect($metrics->collect(await: false))->toBeTrue()
        ->and($metrics->collect(await: false))->toBeFalse()
        ->and($metrics->collect())->toBeFalse();

    $metrics->wait();

    expect($slow->exports)->toBe(1)
        ->and($slow->maxActive)->toBe(1);

    // The final flush waits for an in-flight collect instead of overlapping it.
    $counter->add(2);
    $metrics->collect(await: false);

    expect($metrics->finish())->toBeTrue()
        ->and($slow->exports)->toBe(3)
        ->and($slow->maxActive)->toBe(1)
        ->and(exportedSum($slow->metrics, 'fledge.test.count'))->toBe(3)
        ->and($metrics->collect())->toBeFalse();

    $metrics->stop();
});

it('resets the cardinality guard per collect only with delta temporality', function () {
    [$delta] = metricsRuntime(['metrics' => ['cardinality_limit' => 1]]);
    $delta->provider()->getMeter('tests')->createCounter('fledge.test.count')->add(1);
    $delta->detect('torque', explicit: true);

    $delta->guard()->attributes('fledge.test.count', ['k' => 'a']);

    expect($delta->guard()->attributes('fledge.test.count', ['k' => 'b']))->toBe(CardinalityGuard::OVERFLOW);

    $delta->collect();

    expect($delta->guard()->attributes('fledge.test.count', ['k' => 'b']))->toBe(['k' => 'b']);

    [$cumulative] = metricsRuntime(['metrics' => ['cardinality_limit' => 1, 'temporality' => 'cumulative']]);
    $cumulative->provider()->getMeter('tests')->createCounter('fledge.test.count')->add(1);
    $cumulative->detect('torque', explicit: true);

    $cumulative->guard()->attributes('fledge.test.count', ['k' => 'a']);
    $cumulative->collect();

    expect($cumulative->guard()->attributes('fledge.test.count', ['k' => 'b']))->toBe(CardinalityGuard::OVERFLOW);
});
