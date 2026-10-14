<?php

declare(strict_types=1);

namespace Fledge\Apm;

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\ExportCircuitBreaker;
use Fledge\Apm\Export\FiberBatchSpanProcessor;
use Fledge\Apm\Export\OtlpHttpTransport;
use Fledge\Fiber\Http\FledgeGuzzle;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysRecordSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Builds the Tracer from the `apm` config array (config/apm.php).
 */
final class TracerFactory
{
    public const string PROTOCOL_PROTOBUF = 'http/protobuf';

    public const string PROTOCOL_JSON = 'http/json';

    public const int DEFAULT_FLUSH_BUDGET_MS = 1500;

    public const int DEFAULT_TIMEOUT_MS = 2000;

    public const int DEFAULT_CONNECT_TIMEOUT_MS = 1000;

    /**
     * @param  array<string, mixed>  $config
     * @param  ExportCircuitBreaker|null  $breaker  shared export breaker (the provider builds one from `apm.breaker`); null for none
     */
    public static function make(
        array $config,
        ?FiberContextStorage $storage = null,
        ?SpanExporterInterface $exporter = null,
        ?ExportCircuitBreaker $breaker = null,
    ): Tracer {
        if (! ($config['enabled'] ?? false)) {
            return Tracer::disabled();
        }

        $storage ??= new FiberContextStorage;
        Context::setStorage($storage);

        $keep = $config['keep'] ?? [];
        $batch = $config['batch'] ?? [];
        $budget = self::flushBudgetMs($config);

        $processor = new FiberBatchSpanProcessor(
            $exporter ?? self::exporter($config),
            keepErrors: (bool) ($keep['errors'] ?? true),
            slowThresholdNanos: max(0, (int) ($keep['slow_ms'] ?? 0)) * 1_000_000,
            maxQueueSize: max(1, (int) ($batch['max_queue_size'] ?? 2048)),
            maxExportBatchSize: max(1, (int) ($batch['max_export_batch_size'] ?? 128)),
            flushBudgetMs: $budget,
            breaker: $breaker,
        );

        $provider = new TracerProvider(
            spanProcessors: [$processor],
            sampler: self::sampler($config),
            resource: self::resource($config),
        );

        return new Tracer(
            $provider->getTracer(Tracer::INSTRUMENTATION_NAME),
            $storage,
            $provider,
            $budget,
        );
    }

    /**
     * Total time one flush may take across all its chunks and retries.
     *
     * @param  array<string, mixed>  $config
     */
    public static function flushBudgetMs(array $config): int
    {
        return max(1, (int) ($config['batch']['flush_budget_ms'] ?? self::DEFAULT_FLUSH_BUDGET_MS));
    }

    /**
     * Parent-based ratio sampling. Below a ratio of 1 and with errors or slow
     * traces to keep, the ratio sampler's drops become RECORD_ONLY so the
     * processor can still keep those traces (see FiberBatchSpanProcessor).
     *
     * @param  array<string, mixed>  $config
     */
    public static function sampler(array $config): SamplerInterface
    {
        $ratio = max(0.0, min(1.0, (float) ($config['sample_rate'] ?? 1.0)));

        $root = match (true) {
            $ratio >= 1.0 => new AlwaysOnSampler,
            $ratio <= 0.0 => new AlwaysOffSampler,
            default => new TraceIdRatioBasedSampler($ratio),
        };

        $sampler = new ParentBased($root);

        $keep = $config['keep'] ?? [];
        $tailKeep = (bool) ($keep['errors'] ?? true) || (int) ($keep['slow_ms'] ?? 0) > 0;

        return $ratio < 1.0 && $tailKeep ? new AlwaysRecordSampler($sampler) : $sampler;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function exporter(array $config): SpanExporterInterface
    {
        return match ($config['exporter'] ?? 'otlp') {
            'memory' => new InMemoryExporter,
            default => new SpanExporter(self::transport($config)),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function transport(array $config): OtlpHttpTransport
    {
        $otlp = $config['otlp'] ?? [];
        $factory = new HttpFactory;
        $timeout = max(1, (int) ($otlp['timeout'] ?? self::DEFAULT_TIMEOUT_MS)) / 1000;
        $connectTimeout = min($timeout, max(1, (int) ($otlp['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_MS)) / 1000);

        return new OtlpHttpTransport(
            client: FledgeGuzzle::client(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]),
            requestFactory: $factory,
            streamFactory: $factory,
            endpoint: self::tracesEndpoint($otlp),
            contentType: self::contentType((string) ($otlp['protocol'] ?? self::PROTOCOL_PROTOBUF)),
            headers: self::parseHeaders($otlp['headers'] ?? []),
            compression: self::compression($otlp['compression'] ?? null),
            maxRetries: max(0, (int) ($otlp['max_retries'] ?? 3)),
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );
    }

    /**
     * Gzip unless explicitly turned off ("none"); spans compress well and
     * intakes accept it.
     *
     * @return 'gzip'|null
     */
    public static function compression(mixed $compression): ?string
    {
        if ($compression === null || $compression === '') {
            return 'gzip';
        }

        return strtolower(trim((string) $compression)) === 'gzip' ? 'gzip' : null;
    }

    /**
     * OTEL_EXPORTER_OTLP_TRACES_ENDPOINT is used as is; the generic
     * OTEL_EXPORTER_OTLP_ENDPOINT gets the `/v1/traces` signal path.
     *
     * @param  array<string, mixed>  $otlp
     */
    public static function tracesEndpoint(array $otlp): string
    {
        $traces = $otlp['traces_endpoint'] ?? null;

        if (is_string($traces) && $traces !== '') {
            return $traces;
        }

        return rtrim((string) ($otlp['endpoint'] ?? 'http://localhost:4318'), '/').'/v1/traces';
    }

    /**
     * OTLP/HTTP encodings that need no ext-protobuf (google/protobuf's
     * pure-PHP runtime covers both; ext-protobuf only makes them faster).
     * Unknown values (`grpc`) fall back to protobuf.
     */
    public static function contentType(string $protocol): string
    {
        return match (strtolower(trim($protocol))) {
            self::PROTOCOL_JSON => ContentTypes::JSON,
            default => ContentTypes::PROTOBUF,
        };
    }

    /**
     * Parse OTEL_EXPORTER_OTLP_HEADERS (`k1=v1,k2=v2`, values URL-encoded).
     *
     * @param  string|array<string, string>  $headers
     * @return array<string, string>
     */
    public static function parseHeaders(string|array $headers): array
    {
        if (is_array($headers)) {
            return $headers;
        }

        $parsed = [];

        foreach (explode(',', $headers) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim(rawurldecode($name));

            if ($name !== '') {
                $parsed[$name] = trim(rawurldecode($value));
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function resource(array $config): ResourceInfo
    {
        $service = $config['service'] ?? [];

        $attributes = array_filter([
            ResourceAttributes::SERVICE_NAME => $service['name'] ?? null,
            ResourceAttributes::SERVICE_VERSION => $service['version'] ?? null,
            'deployment.environment.name' => $service['environment'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create($attributes)));
    }
}
