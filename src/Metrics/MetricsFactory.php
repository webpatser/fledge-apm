<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

use ArrayObject;
use Fledge\Apm\Export\BreakerMetricExporter;
use Fledge\Apm\Export\ExportCircuitBreaker;
use Fledge\Apm\Export\OtlpHttpTransport;
use Fledge\Apm\TracerFactory;
use Fledge\Fiber\Http\FledgeGuzzle;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\NoneExemplarFilter;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;
use Throwable;
use UnexpectedValueException;

/**
 * Builds the metrics pipeline from the `apm` config array: one SDK
 * MeterProvider with one ExportingReader in front of the OTLP MetricExporter,
 * which talks to the intake through its own OtlpHttpTransport instance
 * (never the traces one, though both share the process-wide keep-alive
 * connection pool) behind the shared ExportCircuitBreaker.
 *
 * The ExportingReader has no timer: nothing is exported until the caller
 * runs collect(), forceFlush() or shutdown(). None of the three throws.
 *
 * Settings: temporality from `metrics.temporality` (delta by default),
 * exemplar filter none, and no SDK self-metrics (the reader gets no meter
 * provider, so it creates no `otel.sdk.*` instruments).
 *
 * Histogram buckets: SDK default boundaries are milliseconds. Every histogram
 * sets explicit boundaries in seconds through the instrument advisory, e.g.
 * `$meter->createHistogram('x', 's', null, ['ExplicitBucketBoundaries' =>
 * [0.005, 0.01, ...]])`; the reader's default aggregation reads that key
 * (sdk/Metrics/DefaultAggregationProviderTrait.php). No view is needed.
 */
final class MetricsFactory
{
    public const string INSTRUMENTATION_NAME = 'fledge-apm';

    private function __construct(
        private readonly MeterProviderInterface $provider,
        private readonly ExportingReader $reader,
        private readonly MetricExporterInterface $exporter,
    ) {}

    /**
     * Null when APM or metrics are disabled: nothing is constructed then.
     *
     * @param  array<string, mixed>  $config
     * @param  MetricExporterInterface|null  $exporter  replaces the configured exporter (tests)
     * @param  ExportCircuitBreaker|null  $breaker  the breaker the trace path uses; null for none
     * @param  string|null  $role  `fledge.process.role` on the resource; null for the SAPI default
     */
    public static function make(
        array $config,
        ?MetricExporterInterface $exporter = null,
        ?ExportCircuitBreaker $breaker = null,
        ?string $role = null,
    ): ?self {
        if (! ($config['enabled'] ?? false) || ! ($config['metrics']['enabled'] ?? false)) {
            return null;
        }

        $exporter ??= self::exporter($config);
        $reader = new ExportingReader($breaker === null ? $exporter : new BreakerMetricExporter($exporter, $breaker));

        $provider = MeterProvider::builder()
            ->setResource(TracerFactory::resource($config, $role))
            ->setExemplarFilter(new NoneExemplarFilter)
            ->addReader($reader)
            ->build();

        return new self($provider, $reader, $exporter);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function exporter(array $config): MetricExporterInterface
    {
        $temporality = self::temporality($config);

        return match ($config['exporter'] ?? 'otlp') {
            'memory' => new InMemoryExporter(new ArrayObject, $temporality),
            default => new MetricExporter(self::transport($config), $temporality),
        };
    }

    /**
     * `metrics.temporality`: delta (default), cumulative or lowmemory.
     *
     * @param  array<string, mixed>  $config
     */
    public static function temporality(array $config): AggregationTemporalitySelectorInterface
    {
        try {
            return TemporalityPreference::fromName((string) ($config['metrics']['temporality'] ?? 'delta'));
        } catch (UnexpectedValueException) {
            return TemporalityPreference::delta();
        }
    }

    /**
     * A second transport instance, separate from the traces one but on the
     * same process-wide keep-alive connection pool, with the same headers,
     * auth, timeouts and compression. No retries: a collect runs while the
     * worker serves, one attempt per interval is enough and the next interval
     * carries fresh deltas.
     *
     * @param  array<string, mixed>  $config
     */
    public static function transport(array $config): OtlpHttpTransport
    {
        $otlp = $config['otlp'] ?? [];
        $factory = new HttpFactory;
        $timeout = max(1, (int) ($otlp['timeout'] ?? TracerFactory::DEFAULT_TIMEOUT_MS)) / 1000;
        $connectTimeout = min($timeout, max(1, (int) ($otlp['connect_timeout'] ?? TracerFactory::DEFAULT_CONNECT_TIMEOUT_MS)) / 1000);

        return new OtlpHttpTransport(
            client: FledgeGuzzle::client(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]),
            requestFactory: $factory,
            streamFactory: $factory,
            endpoint: TracerFactory::metricsEndpoint($otlp),
            contentType: TracerFactory::contentType((string) ($otlp['protocol'] ?? TracerFactory::PROTOCOL_PROTOBUF)),
            headers: TracerFactory::parseHeaders($otlp['headers'] ?? []),
            compression: TracerFactory::compression($otlp['compression'] ?? null),
            maxRetries: 0,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );
    }

    public function provider(): MeterProviderInterface
    {
        return $this->provider;
    }

    /**
     * The exporter behind the breaker (the in-memory one in tests).
     */
    public function metricExporter(): MetricExporterInterface
    {
        return $this->exporter;
    }

    /**
     * Collect every stream and export the batch. False when the export
     * failed, was skipped by the open breaker, or the reader is shut down.
     */
    public function collect(): bool
    {
        return self::guard(fn (): bool => $this->reader->collect());
    }

    public function forceFlush(): bool
    {
        return self::guard(fn (): bool => $this->reader->forceFlush());
    }

    /**
     * Final collect and export, then closes the exporter; later calls return false.
     */
    public function shutdown(): bool
    {
        return self::guard(fn (): bool => $this->provider->shutdown());
    }

    /**
     * @param  callable(): bool  $operation
     */
    private static function guard(callable $operation): bool
    {
        try {
            return $operation();
        } catch (Throwable) {
            return false;
        }
    }
}
