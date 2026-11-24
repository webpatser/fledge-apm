<?php

declare(strict_types=1);

use Fledge\Apm\Export\BreakerMetricExporter;
use Fledge\Apm\Export\ExportCircuitBreaker;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricExporter\NoopMetricExporter;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

/**
 * A one-metric batch: a counter with one data point, or none.
 *
 * @return list<Metric>
 */
function counterBatch(bool $withPoint = true): array
{
    $points = $withPoint ? [new NumberDataPoint(1, Attributes::create([]), 1, 2)] : [];

    return [new Metric(
        new InstrumentationScope('tests', null, null, Attributes::create([])),
        ResourceInfoFactory::emptyResource(),
        'fledge.test.count',
        null,
        null,
        new Sum($points, Temporality::DELTA, true),
    )];
}

it('returns failure without exporting while the breaker is open', function () {
    $breaker = new ExportCircuitBreaker(30);
    $inner = new InMemoryExporter;
    $exporter = new BreakerMetricExporter($inner, $breaker);

    $breaker->recordFailure();

    expect($exporter->export(counterBatch()))->toBeFalse()
        ->and($exporter->dropped())->toBe(1)
        ->and($inner->collect())->toBe([]);
});

it('opens the shared breaker when the inner export fails', function () {
    $breaker = new ExportCircuitBreaker(30);
    $inner = new InMemoryExporter;
    $inner->shutdown(); // a shut down in-memory exporter rejects every export
    $exporter = new BreakerMetricExporter($inner, $breaker);

    expect($breaker->isOpen())->toBeFalse()
        ->and($exporter->export(counterBatch()))->toBeFalse()
        ->and($breaker->isOpen())->toBeTrue()
        ->and($exporter->dropped())->toBe(1);
});

it('exports and leaves the breaker closed when the inner export succeeds', function () {
    $breaker = new ExportCircuitBreaker(30);
    $inner = new InMemoryExporter;
    $exporter = new BreakerMetricExporter($inner, $breaker);

    expect($exporter->export(counterBatch()))->toBeTrue()
        ->and($breaker->isOpen())->toBeFalse()
        ->and($exporter->dropped())->toBe(0)
        ->and($inner->collect())->toHaveCount(1);
});

it('skips a batch without data points before the inner exporter and the breaker', function (array $batch) {
    $breaker = new ExportCircuitBreaker(30);
    $inner = new InMemoryExporter;
    $inner->shutdown(); // would reject, and so open the breaker, if reached
    $exporter = new BreakerMetricExporter($inner, $breaker);

    expect($exporter->export($batch))->toBeTrue()
        ->and($breaker->isOpen())->toBeFalse()
        ->and($exporter->dropped())->toBe(0);

    $breaker->recordFailure();

    expect($exporter->export($batch))->toBeTrue()
        ->and($exporter->dropped())->toBe(0);
})->with([
    'no metrics' => [[]],
    'a sum without points' => fn (): array => counterBatch(withPoint: false),
    'a gauge and a histogram without points' => fn (): array => [
        new Metric(new InstrumentationScope('tests', null, null, Attributes::create([])), ResourceInfoFactory::emptyResource(), 'fledge.test.gauge', null, null, new Gauge([])),
        new Metric(new InstrumentationScope('tests', null, null, Attributes::create([])), ResourceInfoFactory::emptyResource(), 'fledge.test.duration', 's', null, new Histogram([], Temporality::DELTA)),
    ],
]);

it('passes forceFlush and shutdown through for an exporter that is not a push exporter', function () {
    $exporter = new BreakerMetricExporter(new NoopMetricExporter, new ExportCircuitBreaker(30));

    expect($exporter->forceFlush())->toBeTrue()
        ->and($exporter->shutdown())->toBeTrue();
});
