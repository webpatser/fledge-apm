<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Instrumentation\HttpClientInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/**
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function httpTracer(): array
{
    $result = memoryTracer();

    (new HttpClientInstrumentation)->register(app(), $result[0]);

    return $result;
}

/**
 * @return list<SpanDataInterface>
 */
function httpSpans(Tracer $tracer, InMemoryExporter $exporter): array
{
    $tracer->flush();

    return array_values(array_filter(
        $exporter->getSpans(),
        static fn (SpanDataInterface $span): bool => $span->getKind() === SpanKind::KIND_CLIENT,
    ));
}

it('records a CLIENT span and injects the span traceparent into the request', function () {
    [$tracer, $exporter] = httpTracer();
    $seen = null;

    Http::fake(function (Request $request) use (&$seen) {
        $seen = $request->header('traceparent')[0] ?? null;

        return Http::response('ok', 200);
    });

    $parent = $tracer->startSpan('GET /');
    $response = Http::get('https://api.example.test/items?page=2');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $attributes = $span->getAttributes();

    expect($response->status())->toBe(200)
        ->and($span->getName())->toBe('GET api.example.test')
        ->and($span->getParentSpanId())->toBe($parent->span->getContext()->getSpanId())
        ->and($attributes->get('http.request.method'))->toBe('GET')
        ->and($attributes->get('url.full'))->toBe('https://api.example.test/items?page=2')
        ->and($attributes->get('server.address'))->toBe('api.example.test')
        ->and($attributes->get('server.port'))->toBe(443)
        ->and($attributes->get('http.response.status_code'))->toBe(200)
        ->and($span->getStatus()->getCode())->not->toBe(StatusCode::STATUS_ERROR)
        // The downstream service joins the trace as a child of the client span.
        ->and($seen)->toBe(sprintf('00-%s-%s-01', $span->getTraceId(), $span->getSpanId()));
});

it('marks 5xx responses as errors', function (int $status) {
    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('nope', $status)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($span->getAttributes()->get('http.response.status_code'))->toBe($status)
        ->and($span->getAttributes()->get('error.type'))->toBe((string) $status);
})->with([500, 503]);

it('does not mark 4xx responses as errors by default', function (int $status) {
    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('nope', $status)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($span->getAttributes()->get('http.response.status_code'))->toBe($status)
        ->and($span->getAttributes()->has('error.type'))->toBeFalse();
})->with([404, 422]);

it('marks 4xx responses as errors when error_on_4xx is on', function () {
    config()->set('apm.options.http_client.error_on_4xx', true);

    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('nope', 404)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($span->getAttributes()->get('error.type'))->toBe('404');
});

it('records a failed connection as an error with the exception', function () {
    [$tracer, $exporter] = httpTracer();

    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $parent = $tracer->startSpan('GET /');

    expect(fn () => Http::get('https://down.example.test/'))->toThrow(ConnectionException::class);

    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($span->getAttributes()->get('error.type'))->toBe(ConnectionException::class)
        ->and($span->getEvents())->toHaveCount(1)
        ->and($span->getEvents()[0]->getName())->toBe('exception');
});

it('strips credentials and redacts secret-looking query values from url.full', function () {
    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('ok')]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://user:hunter2@api.example.test/items?api_key=abc123&page=2&X-Amz-Signature=sig#frag');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $url = $span->getAttributes()->get('url.full');

    expect($url)->toBe('https://api.example.test/items?api_key=REDACTED&page=2&X-Amz-Signature=REDACTED')
        ->and($url)->not->toContain('hunter2')
        ->and($url)->not->toContain('abc123');
});

it('does not trace or touch requests sent outside a trace', function () {
    [$tracer, $exporter] = httpTracer();
    $seen = 'unset';

    Http::fake(function (Request $request) use (&$seen) {
        $seen = $request->hasHeader('traceparent');

        return Http::response('ok');
    });

    Http::get('https://api.example.test/');

    expect($seen)->toBeFalse()
        ->and(httpSpans($tracer, $exporter))->toBe([]);
});

it('leaves the request headers alone when propagation is off', function () {
    config(['apm.options.http_client.propagate' => false]);

    [$tracer, $exporter] = httpTracer();
    $seen = 'unset';

    Http::fake(function (Request $request) use (&$seen) {
        $seen = $request->hasHeader('traceparent');

        return Http::response('ok');
    });

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();

    expect($seen)->toBeFalse()
        ->and(httpSpans($tracer, $exporter))->toHaveCount(1);
});

it('keeps a traceparent the caller set explicitly', function () {
    [$tracer] = httpTracer();
    $seen = null;

    Http::fake(function (Request $request) use (&$seen) {
        $seen = $request->header('traceparent')[0] ?? null;

        return Http::response('ok');
    });

    $parent = $tracer->startSpan('GET /');
    Http::withHeaders(['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])->get('https://api.example.test/');
    $parent->end();

    expect($seen)->toBe('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
});

it('registers on a Factory that was resolved before the instrumentation', function () {
    // The usual case: something used Http during boot.
    $before = app(Factory::class);

    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('ok')]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();

    expect(Http::getFacadeRoot())->toBe($before)
        ->and(httpSpans($tracer, $exporter))->toHaveCount(1);
});

it('registers on a Factory that is resolved after the instrumentation', function () {
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);

    expect(app()->resolved(Factory::class))->toBeFalse();

    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('ok')]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    Http::get('https://api.example.test/again');
    $parent->end();

    // Patched once, not once per resolution.
    expect(httpSpans($tracer, $exporter))->toHaveCount(2);
});

it('gives requests sent from parallel fibers their own span under their own parent', function () {
    [$tracer, $exporter, $storage] = httpTracer();
    $propagation = new Propagation($storage);
    $seen = [];

    Http::fake(function (Request $request) use (&$seen) {
        $seen[$request->url()] = $request->header('traceparent')[0] ?? null;

        return Http::response('ok');
    });

    $root = $tracer->startSpan('GET /pulse');
    $futures = [];

    foreach ([1, 2] as $card) {
        $futures[$card] = async($propagation->wrap(
            fn (): string => $tracer->span("card {$card}", function ($span) use ($card): string {
                Http::get("https://api.example.test/card/{$card}");

                return $span->span->getContext()->getSpanId();
            }),
        ));
    }

    $cards = await($futures);
    $root->end();

    $spans = collect(httpSpans($tracer, $exporter))->keyBy(fn (SpanDataInterface $span) => $span->getAttributes()->get('url.full'));

    foreach ([1, 2] as $card) {
        $span = $spans["https://api.example.test/card/{$card}"];

        expect($span->getParentSpanId())->toBe($cards[$card])
            ->and($seen["https://api.example.test/card/{$card}"])->toBe(sprintf('00-%s-%s-01', $span->getTraceId(), $span->getSpanId()));
    }
});
