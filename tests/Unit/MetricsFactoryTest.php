<?php

declare(strict_types=1);

use Fledge\Apm\Export\ExportCircuitBreaker;
use Fledge\Apm\Metrics\MetricsFactory;
use Fledge\Apm\TracerFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;

/**
 * @param  array<string, mixed>  $config
 */
function memoryMetrics(array $config = [], ?ExportCircuitBreaker $breaker = null, ?string $role = null): MetricsFactory
{
    $metrics = MetricsFactory::make(array_replace_recursive([
        'enabled' => true,
        'exporter' => 'memory',
        'service' => ['name' => 'fledge-apm-tests'],
        'metrics' => ['enabled' => true],
    ], $config), breaker: $breaker, role: $role);

    expect($metrics)->not->toBeNull();

    return $metrics;
}

/**
 * @return list<Metric>
 */
function exportedMetrics(MetricsFactory $metrics): array
{
    $exporter = $metrics->metricExporter();
    expect($exporter)->toBeInstanceOf(InMemoryExporter::class);

    /** @var InMemoryExporter $exporter */
    return array_values($exporter->collect(reset: true));
}

it('constructs nothing when APM or metrics are disabled', function () {
    expect(MetricsFactory::make(['enabled' => true, 'metrics' => ['enabled' => false]]))->toBeNull()
        ->and(MetricsFactory::make(['enabled' => false, 'metrics' => ['enabled' => true]]))->toBeNull()
        ->and(MetricsFactory::make((array) config('apm')))->toBeNull();
});

it('exports a counter on collect with delta temporality', function () {
    $metrics = memoryMetrics(breaker: new ExportCircuitBreaker(30));
    $counter = $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count');

    $counter->add(3, ['messaging.system' => 'redis']);

    expect(exportedMetrics($metrics))->toBe([]);

    expect($metrics->collect())->toBeTrue();
    $first = exportedMetrics($metrics);

    $counter->add(2, ['messaging.system' => 'redis']);
    $metrics->collect();
    $second = exportedMetrics($metrics);

    expect($first)->toHaveCount(1)
        ->and($first[0]->name)->toBe('fledge.test.count')
        ->and($first[0]->data)->toBeInstanceOf(Sum::class)
        ->and($first[0]->data->temporality)->toBe(Temporality::DELTA)
        ->and([...$first[0]->data->dataPoints][0]->value)->toBe(3)
        ->and([...$second[0]->data->dataPoints][0]->value)->toBe(2);
});

it('falls back to delta for an unknown temporality and honours cumulative', function () {
    $metrics = memoryMetrics(['metrics' => ['temporality' => 'cumulative']]);
    $counter = $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count');

    $counter->add(3);
    $metrics->collect();
    $counter->add(2);
    $metrics->collect();
    $exported = exportedMetrics($metrics);

    expect($exported[0]->data->temporality)->toBe(Temporality::CUMULATIVE)
        ->and([...$exported[1]->data->dataPoints][0]->value)->toBe(5);

    $fallback = memoryMetrics(['metrics' => ['temporality' => 'nonsense']]);
    $fallback->provider()->getMeter('tests')->createCounter('fledge.test.count')->add(1);
    $fallback->collect();

    expect(exportedMetrics($fallback)[0]->data->temporality)->toBe(Temporality::DELTA);
});

it('takes explicit histogram boundaries from the instrument advisory', function () {
    $metrics = memoryMetrics();
    $bounds = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    $metrics->provider()->getMeter('tests')
        ->createHistogram('fledge.test.duration', 's', null, ['ExplicitBucketBoundaries' => $bounds])
        ->record(0.2);
    $metrics->collect();
    $exported = exportedMetrics($metrics);

    expect($exported[0]->data)->toBeInstanceOf(Histogram::class)
        ->and($exported[0]->unit)->toBe('s')
        ->and([...$exported[0]->data->dataPoints][0]->explicitBounds)->toBe($bounds)
        ->and([...$exported[0]->data->dataPoints][0]->exemplars)->toBe([]);
});

it('drops the batch while the shared breaker is open and never throws', function () {
    $breaker = new ExportCircuitBreaker(30);
    $metrics = memoryMetrics(breaker: $breaker);
    $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count')->add(1);

    $breaker->recordFailure();

    expect($metrics->collect())->toBeFalse()
        ->and(exportedMetrics($metrics))->toBe([]);
});

it('puts the process role and instance id on the metrics resource', function () {
    $metrics = memoryMetrics(role: 'torque');
    $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count')->add(1);
    $metrics->collect();
    $attributes = exportedMetrics($metrics)[0]->resource->getAttributes();

    expect($attributes->get(TracerFactory::PROCESS_ROLE))->toBe('torque')
        ->and($attributes->get(TracerFactory::SERVICE_INSTANCE_ID))->toBe(TracerFactory::serviceInstanceId())
        ->and($attributes->get('service.name'))->toBe('fledge-apm-tests');
});

it('returns false after shutdown instead of throwing', function () {
    $metrics = memoryMetrics();

    expect($metrics->shutdown())->toBeTrue()
        ->and($metrics->collect())->toBeFalse()
        ->and($metrics->forceFlush())->toBeFalse()
        ->and($metrics->shutdown())->toBeFalse();
});

it('builds the OTLP exporter over its own transport to the metrics endpoint without retries', function () {
    $config = ['otlp' => ['endpoint' => 'http://apm.test:8200', 'compression' => 'gzip']];
    $transport = MetricsFactory::transport($config);

    expect(MetricsFactory::exporter($config))->toBeInstanceOf(MetricExporter::class)
        ->and((new ReflectionProperty($transport, 'endpoint'))->getValue($transport))->toBe('http://apm.test:8200/v1/metrics')
        ->and((new ReflectionProperty($transport, 'maxRetries'))->getValue($transport))->toBe(0)
        ->and((new ReflectionProperty($transport, 'compression'))->getValue($transport))->toBe('gzip')
        ->and($transport)->not->toBe(TracerFactory::transport($config));
});
