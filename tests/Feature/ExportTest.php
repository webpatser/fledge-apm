<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\OtlpHttpTransport;
use Fledge\Apm\Tracer;
use Fledge\Apm\TracerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Http\Message\RequestInterface;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * @param  list<Response>  $responses
 * @param  array<int, array{request: RequestInterface}>  $history
 */
function mockTransport(array $responses, array &$history, string $contentType, ?string $compression = null): OtlpHttpTransport
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $factory = new HttpFactory;

    return new OtlpHttpTransport(
        client: new Client(['handler' => $stack]),
        requestFactory: $factory,
        streamFactory: $factory,
        endpoint: 'http://apm.test:8200/v1/traces',
        contentType: $contentType,
        headers: ['Authorization' => 'Bearer secret'],
        compression: $compression,
        maxRetries: 2,
        retryDelaySeconds: 0.001,
    );
}

it('exports OTLP/HTTP protobuf through a PSR-18 client', function () {
    $history = [];
    $transport = mockTransport([new Response(200)], $history, ContentTypes::PROTOBUF);
    $tracer = exportingTracer(new SpanExporter($transport));

    $tracer->span('GET /', fn () => $tracer->span('select', fn () => null, SpanKind::KIND_CLIENT), SpanKind::KIND_SERVER);

    expect($tracer->flush())->toBeTrue()
        ->and($history)->toHaveCount(1);

    $request = $history[0]['request'];

    expect((string) $request->getUri())->toBe('http://apm.test:8200/v1/traces')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/x-protobuf')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret');

    $decoded = new ExportTraceServiceRequest;
    $decoded->mergeFromString((string) $request->getBody());

    $spans = $decoded->getResourceSpans()[0]->getScopeSpans()[0]->getSpans();
    $names = array_map(fn ($span) => $span->getName(), iterator_to_array($spans));

    expect($names)->toEqualCanonicalizing(['GET /', 'select']);
});

it('exports OTLP/HTTP JSON with hex ids and gzip', function () {
    $history = [];
    $transport = mockTransport([new Response(200, ['Content-Type' => 'application/json'], '{}')], $history, ContentTypes::JSON, 'gzip');
    $tracer = exportingTracer(new SpanExporter($transport));

    $tracer->span('job', fn () => null);

    expect($tracer->flush())->toBeTrue();

    $request = $history[0]['request'];
    $json = json_decode(gzdecode((string) $request->getBody()), true);
    $span = $json['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($span['name'])->toBe('job')
        ->and($span['traceId'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($span['spanId'])->toMatch('/^[0-9a-f]{16}$/');
});

it('retries 503 responses with a non-blocking backoff on the final flush', function () {
    $history = [];
    $transport = mockTransport([new Response(503), new Response(503), new Response(200)], $history, ContentTypes::PROTOBUF);
    $tracer = exportingTracer(new SpanExporter($transport));

    $tracer->span('retry me', fn () => null);

    expect($tracer->flush(retry: true))->toBeTrue()
        ->and($history)->toHaveCount(3);
});

it('does not retry on a regular flush', function () {
    $history = [];
    $transport = mockTransport([new Response(503), new Response(200)], $history, ContentTypes::PROTOBUF);
    $tracer = exportingTracer(new SpanExporter($transport));

    $tracer->span('no second chance', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($history)->toHaveCount(1);
});

it('reports a failed export without throwing', function () {
    $history = [];
    $transport = mockTransport([new Response(400, [], 'bad request')], $history, ContentTypes::PROTOBUF);
    $tracer = exportingTracer(new SpanExporter($transport));

    $tracer->span('rejected', fn () => null);

    expect($tracer->flush())->toBeFalse();
});

it('fails fast through the FledgeGuzzle client when the intake is down', function () {
    $tracer = TracerFactory::make([
        'enabled' => true,
        'otlp' => ['endpoint' => 'http://127.0.0.1:1', 'max_retries' => 0, 'timeout' => 2000],
    ]);

    $tracer->span('nobody listens', fn () => null);

    expect($tracer->flush())->toBeFalse();
});

it('keeps failed and slow traces the ratio sampler dropped', function () {
    [$tracer, $exporter] = memoryTracer([
        'sample_rate' => 0.0,
        'keep' => ['errors' => true, 'slow_ms' => 50],
    ]);

    $tracer->span('fast ok', fn () => $tracer->span('child', fn () => null));

    try {
        $tracer->span('fails', fn () => $tracer->span('boom', fn () => throw new RuntimeException('boom')));
    } catch (RuntimeException) {
    }

    $slow = $tracer->startSpan('slow', startEpochNanos: Tracer::nanosAgo(100));
    $tracer->span('slow child', fn () => null);
    $slow->end();

    $tracer->flush();

    $names = array_map(fn (SpanDataInterface $span) => $span->getName(), $exporter->getSpans());

    expect($names)->toEqualCanonicalizing(['boom', 'fails', 'slow child', 'slow']);

    $failed = collect($exporter->getSpans())->first(fn (SpanDataInterface $span) => $span->getName() === 'boom');

    expect($failed->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($failed->getEvents()[0]->getName())->toBe('exception');
});

it('records nothing for dropped traces with plain head sampling', function () {
    [$tracer, $exporter] = memoryTracer([
        'sample_rate' => 0.0,
        'keep' => ['errors' => false, 'slow_ms' => 0],
    ]);

    $span = $tracer->startSpan('dropped');

    expect($span->isRecording())->toBeFalse();

    $span->end();
    $tracer->flush();

    expect($exporter->getSpans())->toBe([]);
});

it('back-dates recorded spans', function () {
    [$tracer, $exporter] = memoryTracer();

    $tracer->span('request', fn () => $tracer->recordSpan('select * from users', Tracer::nanosAgo(25), kind: SpanKind::KIND_CLIENT));
    $tracer->flush();

    $query = collect($exporter->getSpans())->first(fn (SpanDataInterface $span) => $span->getName() === 'select * from users');
    $durationMs = ($query->getEndEpochNanos() - $query->getStartEpochNanos()) / 1_000_000;

    expect($durationMs)->toBeGreaterThanOrEqual(25.0)->toBeLessThan(250.0);
});

it('keeps spans that end while an export is suspended for the next flush', function () {
    $exporter = new class extends InMemoryExporter
    {
        public int $exports = 0;

        protected function doExport(iterable $spans): bool
        {
            $this->exports++;
            delay(0.01);

            return parent::doExport($spans);
        }
    };

    $tracer = exportingTracer($exporter);

    $tracer->span('first', fn () => null);

    $flushing = async(fn (): bool => $tracer->flush());
    delay(0.001); // the export is now suspended inside the exporter

    $tracer->span('during export', fn () => null);

    expect($flushing->await())->toBeTrue()
        ->and($tracer->flush())->toBeTrue();

    $names = array_map(fn (SpanDataInterface $span) => $span->getName(), $exporter->getSpans());

    expect($names)->toBe(['first', 'during export'])
        ->and($exporter->exports)->toBe(2);
});

/**
 * An enabled Tracer on the real SDK pipeline, exporting through the given exporter.
 */
function exportingTracer(SpanExporterInterface $exporter): Tracer
{
    return TracerFactory::make(['enabled' => true, 'keep' => ['slow_ms' => 0]], new FiberContextStorage, $exporter);
}
