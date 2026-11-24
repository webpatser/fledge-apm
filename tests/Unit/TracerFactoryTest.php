<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\TracerFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysRecordSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

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

it('resolves the metrics endpoint', function (array $otlp, string $endpoint) {
    expect(TracerFactory::metricsEndpoint($otlp))->toBe($endpoint);
})->with([
    'base endpoint' => [['endpoint' => 'https://apm.example:8200/'], 'https://apm.example:8200/v1/metrics'],
    'signal endpoint wins' => [['endpoint' => 'https://a', 'metrics_endpoint' => 'https://b/custom'], 'https://b/custom'],
    'empty signal endpoint' => [['endpoint' => 'https://a', 'metrics_endpoint' => ''], 'https://a/v1/metrics'],
    'traces endpoint ignored' => [['endpoint' => 'https://a', 'traces_endpoint' => 'https://b/traces'], 'https://a/v1/metrics'],
    'default' => [[], 'http://localhost:4318/v1/metrics'],
]);

it('puts the instance id and process role on the resource', function () {
    $default = TracerFactory::resource(['service' => ['name' => 'svc']])->getAttributes();
    $marked = TracerFactory::resource([], 'torque')->getAttributes();

    expect($default->get('service.instance.id'))->toBe(gethostname().':'.getmypid())
        ->and($default->get('fledge.process.role'))->toBe('cli')
        ->and($default->get('service.name'))->toBe('svc')
        ->and($marked->get('fledge.process.role'))->toBe('torque');
});

it('puts the instance id and process role on the trace resource only with metrics enabled', function (bool $metrics) {
    $exporter = new InMemoryExporter;
    $tracer = TracerFactory::make(
        ['enabled' => true, 'service' => ['name' => 'svc'], 'metrics' => ['enabled' => $metrics]],
        new FiberContextStorage,
        $exporter,
    );

    $tracer->startSpan('job', activate: false)->end();
    $tracer->flush();

    $attributes = $exporter->getSpans()[0]->getResource()->getAttributes();

    expect($attributes->get('service.name'))->toBe('svc')
        ->and($attributes->has('service.instance.id'))->toBe($metrics)
        ->and($attributes->has('fledge.process.role'))->toBe($metrics);
})->with(['metrics off' => false, 'metrics on' => true]);

it('leaves the process attributes off a resource built without them', function () {
    $attributes = TracerFactory::resource(['service' => ['name' => 'svc']], 'torque', process: false)->getAttributes();

    expect($attributes->get('service.name'))->toBe('svc')
        ->and($attributes->has('service.instance.id'))->toBeFalse()
        ->and($attributes->has('fledge.process.role'))->toBeFalse();
});

it('records dropped traces only when there is something to keep', function () {
    expect(TracerFactory::sampler(['sample_rate' => 0.1]))->toBeInstanceOf(AlwaysRecordSampler::class)
        ->and(TracerFactory::sampler(['sample_rate' => 0.1, 'keep' => ['errors' => false, 'slow_ms' => 0]]))->toBeInstanceOf(ParentBased::class)
        ->and(TracerFactory::sampler(['sample_rate' => 1.0]))->toBeInstanceOf(ParentBased::class);
});
