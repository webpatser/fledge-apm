<?php

declare(strict_types=1);

namespace Fledge\Apm\Export;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use Throwable;

/**
 * Puts the metric exporter behind the ExportCircuitBreaker the trace path
 * uses, so an unreachable intake trips one breaker for both signals.
 *
 * While the breaker is open the batch is dropped (with delta temporality the
 * next interval starts from zero again). A failed or throwing export opens
 * it. Nothing here throws into the application.
 */
final class BreakerMetricExporter implements AggregationTemporalitySelectorInterface, PushMetricExporterInterface
{
    private int $dropped = 0;

    public function __construct(
        private readonly MetricExporterInterface $exporter,
        private readonly ExportCircuitBreaker $breaker,
    ) {}

    /**
     * The reader registers a stream only when its exporter selects a
     * temporality, so this delegates (null skips the stream).
     */
    public function temporality(MetricMetadataInterface $metric): string|Temporality|null
    {
        return $this->exporter instanceof AggregationTemporalitySelectorInterface
            ? $this->exporter->temporality($metric)
            : null;
    }

    /**
     * A batch without a single data point (an idle interval, a final flush
     * with nothing recorded) is not sent and does not touch the breaker.
     */
    public function export(iterable $batch): bool
    {
        if (self::withoutDataPoints($batch)) {
            return true;
        }

        if ($this->breaker->isOpen()) {
            $this->dropped++;

            return false;
        }

        try {
            $exported = $this->exporter->export($batch);
        } catch (Throwable) {
            $exported = false;
        }

        if (! $exported) {
            $this->dropped++;
            $this->breaker->recordFailure();
        }

        return $exported;
    }

    public function shutdown(): bool
    {
        try {
            return $this->exporter->shutdown();
        } catch (Throwable) {
            return false;
        }
    }

    public function forceFlush(): bool
    {
        try {
            return $this->exporter instanceof PushMetricExporterInterface ? $this->exporter->forceFlush() : true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True only when every metric in an array batch is a Sum, Gauge or
     * Histogram with an empty data point array. Anything the check cannot see
     * into (a generator, another data type) counts as data, so it is sent.
     *
     * @param  iterable<mixed>  $batch
     */
    private static function withoutDataPoints(iterable $batch): bool
    {
        if (! is_array($batch)) {
            return false; // inspecting a generator would consume it
        }

        foreach ($batch as $metric) {
            $data = $metric instanceof Metric ? $metric->data : null;
            $points = $data instanceof Sum || $data instanceof Gauge || $data instanceof Histogram ? $data->dataPoints : null;

            if ($points !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Batches not exported (breaker open or export failed) since construction.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }
}
