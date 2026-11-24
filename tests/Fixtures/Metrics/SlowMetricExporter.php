<?php

declare(strict_types=1);

namespace Fledge\Apm\Tests\Fixtures\Metrics;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

use function Fledge\Async\delay;

/**
 * A metric exporter whose export suspends the fiber for `$seconds`, like a
 * slow intake, and records how many exports ran at the same time.
 */
final class SlowMetricExporter implements AggregationTemporalitySelectorInterface, MetricExporterInterface, PushMetricExporterInterface
{
    public int $exports = 0;

    public int $active = 0;

    public int $maxActive = 0;

    /** @var list<Metric> */
    public array $metrics = [];

    public function __construct(private readonly float $seconds = 0.2) {}

    public function temporality(MetricMetadataInterface $metric): string|Temporality|null
    {
        return TemporalityPreference::delta()->temporality($metric);
    }

    public function export(iterable $batch): bool
    {
        $this->active++;
        $this->maxActive = max($this->maxActive, $this->active);

        try {
            delay($this->seconds);

            foreach ($batch as $metric) {
                $this->metrics[] = $metric;
            }

            $this->exports++;

            return true;
        } finally {
            $this->active--;
        }
    }

    public function shutdown(): bool
    {
        return true;
    }

    public function forceFlush(): bool
    {
        return true;
    }
}
