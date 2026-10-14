<?php

declare(strict_types=1);

use Fledge\Apm\TracerFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysRecordSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;

it('maps OTLP protocols to content types, defaulting to protobuf', function (string $protocol, string $contentType) {
    expect(TracerFactory::contentType($protocol))->toBe($contentType);
})->with([
    'protobuf' => ['http/protobuf', 'application/x-protobuf'],
    'json' => ['http/json', 'application/json'],
    'grpc falls back' => ['grpc', 'application/x-protobuf'],
]);

it('parses OTEL_EXPORTER_OTLP_HEADERS', function () {
    expect(TracerFactory::parseHeaders('Authorization=Bearer%20secret, X-Tenant = acme ,broken'))
        ->toBe(['Authorization' => 'Bearer secret', 'X-Tenant' => 'acme']);
});

it('resolves the traces endpoint', function () {
    expect(TracerFactory::tracesEndpoint(['endpoint' => 'https://apm.example:8200/']))
        ->toBe('https://apm.example:8200/v1/traces')
        ->and(TracerFactory::tracesEndpoint(['endpoint' => 'https://a', 'traces_endpoint' => 'https://b/custom']))
        ->toBe('https://b/custom');
});

it('records dropped traces only when there is something to keep', function () {
    expect(TracerFactory::sampler(['sample_rate' => 0.1]))->toBeInstanceOf(AlwaysRecordSampler::class)
        ->and(TracerFactory::sampler(['sample_rate' => 0.1, 'keep' => ['errors' => false, 'slow_ms' => 0]]))->toBeInstanceOf(ParentBased::class)
        ->and(TracerFactory::sampler(['sample_rate' => 1.0]))->toBeInstanceOf(ParentBased::class);
});
