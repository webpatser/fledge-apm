<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Replaces the resource on exported metrics once the process role is known.
 *
 * The MeterProvider's resource is fixed when it is built, which is at the
 * first meter() call (often at boot), before the Looping, WorkerStarting or
 * markLongRunning() signal names the role. Until a resource is set the batch
 * passes through untouched.
 */
final class ProcessResourceExporter implements AggregationTemporalitySelectorInterface, PushMetricExporterInterface
{
    private ?ResourceInfo $resource = null;

    public function __construct(private readonly MetricExporterInterface $exporter) {}

    public function useResource(ResourceInfo $resource): void
    {
        $this->resource = $resource;
    }

    public function exporter(): MetricExporterInterface
    {
        return $this->exporter;
    }

    public function temporality(MetricMetadataInterface $metric): string|Temporality|null
    {
        return $this->exporter instanceof AggregationTemporalitySelectorInterface
            ? $this->exporter->temporality($metric)
            : null;
    }

    public function export(iterable $batch): bool
    {
        $resource = $this->resource;

        if ($resource === null) {
            return $this->exporter->export($batch);
        }

        $stamped = [];

        foreach ($batch as $metric) {
            $stamped[] = new Metric(
                $metric->instrumentationScope,
                $resource,
                $metric->name,
                $metric->unit,
                $metric->description,
                $metric->data,
            );
        }

        return $this->exporter->export($stamped);
    }

    public function shutdown(): bool
    {
        return $this->exporter->shutdown();
    }

    public function forceFlush(): bool
    {
        return $this->exporter instanceof PushMetricExporterInterface ? $this->exporter->forceFlush() : true;
    }
}
