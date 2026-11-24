<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Instrumentation\HttpClientInstrumentation;
use Fledge\Apm\Tracer;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Psr\Http\Message\RequestInterface;

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

/**
 * The traceparent of a request sent from $span: version 00, the trace id and
 * span id of $span, and the sampled flag set. The other flag bits belong to the
 * SDK: since open-telemetry/sdk 1.16 a root span also carries the W3C level 2
 * random trace id flag, so the flags byte is 03 there and 01 on older SDKs.
 */
function expectChildTraceparent(?string $traceparent, SpanDataInterface $span): void
{
    expect($traceparent)->toMatch(sprintf('/^00-%s-%s-[0-9a-f]{2}$/', $span->getTraceId(), $span->getSpanId()))
        ->and(hexdec(substr((string) $traceparent, -2)) & TraceFlags::SAMPLED)->toBe(TraceFlags::SAMPLED);
}

/**
 * A Guzzle transport standing in for the network: it reports $stats through
 * `on_stats` before settling the promise, in the order the Fledge and curl
 * handlers use, and hands the TransferStats and the callback it saw back.
 *
 * @param  array<string, mixed>  $stats
 */
function httpStatsHandler(array $stats, ?TransferStats &$sent = null, mixed &$callback = null, bool $fail = false): Closure
{
    return function (RequestInterface $request, array $options) use ($stats, $fail, &$sent, &$callback): PromiseInterface {
        $response = $fail ? null : new Psr7Response(200, [], 'hello');
        $error = $fail ? new ConnectException('Connection refused', $request) : null;
        $callback = $options['on_stats'] ?? null;

        if (is_callable($callback)) {
            $sent = new TransferStats($request, $response, 0.05, $error, $stats);
            $callback($sent);
        }

        return $fail ? Create::rejectionFor($error) : Create::promiseFor($response);
    };
}

/**
 * Handler stats as the Fledge handler with connection reuse reporting builds them.
 *
 * @return array<string, mixed>
 */
function fledgeHandlerStats(array $overrides = []): array
{
    return [
        'total_time' => 0.05,
        'namelookup_time' => 0.0,
        'connect_time' => 0.012,
        'appconnect_time' => 0.008,
        'pretransfer_time' => 0.013,
        'starttransfer_time' => 0.042,
        'primary_ip' => '192.0.2.10',
        'primary_port' => 443,
        'size_download' => 5,
        'http_code' => 200,
        'url' => 'https://api.example.test/items',
        'handler' => 'fledge',
        'connection_reused' => false,
        'alpn' => 'h2',
        ...$overrides,
    ];
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
        ->and($span->getStatus()->getCode())->not->toBe(StatusCode::STATUS_ERROR);

    // The downstream service joins the trace as a child of the client span.
    expectChildTraceparent($seen, $span);
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

it('redacts credentials carried in the path from url.full', function () {
    [$tracer, $exporter] = httpTracer();
    $sent = null;

    Http::fake(function (Request $request) use (&$sent) {
        $sent = $request->url();

        return Http::response('ok');
    });

    $parent = $tracer->startSpan('GET /');
    Http::post('https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage?chat_id=7');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getAttributes()->get('url.full'))->toBe('https://api.telegram.org/botREDACTED/sendMessage?chat_id=7')
        ->and($span->getName())->toBe('POST api.telegram.org')
        // The request itself is untouched.
        ->and($sent)->toContain('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');
});

it('keeps the path in url.full verbatim when record_full_path is on', function () {
    config()->set('apm.options.http_client.record_full_path', true);

    [$tracer, $exporter] = httpTracer();

    Http::fake(['*' => Http::response('ok')]);

    $parent = $tracer->startSpan('GET /');
    $hook = 'https://hooks.slack.com/'.'services/'.implode('/', ['T00000000', 'B00000000', str_repeat('X', 24)]);
    Http::post($hook.'?token=t');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getAttributes()->get('url.full'))->toBe($hook.'?token=REDACTED');
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

        expect($span->getParentSpanId())->toBe($cards[$card]);

        expectChildTraceparent($seen["https://api.example.test/card/{$card}"], $span);
    }
});

it('records the transfer timings of the Fledge handler on the span', function () {
    [$tracer, $exporter] = httpTracer();

    $parent = $tracer->startSpan('GET /');
    $response = Http::setHandler(httpStatsHandler(fledgeHandlerStats()))->get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $attributes = $span->getAttributes();

    expect($response->body())->toBe('hello')
        ->and($attributes->get('network.peer.address'))->toBe('192.0.2.10')
        ->and($attributes->get('network.peer.port'))->toBe(443)
        ->and($attributes->get('network.protocol.version'))->toBe('2')
        ->and($attributes->get('http.response.body.size'))->toBe(5)
        // Fledge: connect_time already includes TLS, appconnect_time is TLS alone.
        ->and($attributes->get('fledge.http.connect_ms'))->toBe(12.0)
        ->and($attributes->get('fledge.http.tls_ms'))->toBe(8.0)
        ->and($attributes->get('fledge.http.ttfb_ms'))->toBe(42.0)
        ->and($attributes->get('fledge.http.connection_reused'))->toBeFalse()
        ->and($attributes->get('http.response.status_code'))->toBe(200);
});

it('records a reused connection', function () {
    [$tracer, $exporter] = httpTracer();

    $parent = $tracer->startSpan('GET /');
    Http::setHandler(httpStatsHandler(fledgeHandlerStats([
        'connect_time' => 0.0,
        'appconnect_time' => 0.0,
        'connection_reused' => true,
        'alpn' => 'http/1.1',
    ])))->get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $attributes = $span->getAttributes();

    expect($attributes->get('fledge.http.connection_reused'))->toBeTrue()
        ->and($attributes->get('fledge.http.connect_ms'))->toBe(0.0)
        ->and($attributes->get('fledge.http.tls_ms'))->toBe(0.0)
        ->and($attributes->get('network.protocol.version'))->toBe('1.1');
});

it('reads curl-shaped stats without the Fledge-only keys', function () {
    [$tracer, $exporter] = httpTracer();

    // The stock curl handler: cumulative times, no handler, connection_reused or alpn.
    $stats = [
        'total_time' => 0.06,
        'connect_time' => 0.01,
        'appconnect_time' => 0.03,
        'starttransfer_time' => 0.05,
        'primary_ip' => '198.51.100.7',
        'primary_port' => 8443,
        'size_download' => 5.0,
    ];

    $parent = $tracer->startSpan('GET /');
    $response = Http::setHandler(httpStatsHandler($stats))->get('https://api.example.test:8443/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $attributes = $span->getAttributes();

    expect($response->status())->toBe(200)
        ->and($attributes->get('network.peer.address'))->toBe('198.51.100.7')
        ->and($attributes->get('network.peer.port'))->toBe(8443)
        ->and($attributes->get('http.response.body.size'))->toBe(5)
        ->and($attributes->get('fledge.http.connect_ms'))->toBe(30.0)
        ->and($attributes->get('fledge.http.tls_ms'))->toBe(20.0)
        ->and($attributes->get('fledge.http.ttfb_ms'))->toBe(50.0)
        // No alpn: the protocol version comes from the response.
        ->and($attributes->get('network.protocol.version'))->toBe('1.1')
        ->and($attributes->has('fledge.http.connection_reused'))->toBeFalse();
});

it('falls back to the response protocol version when alpn is unusable', function (mixed $alpn) {
    [$tracer, $exporter] = httpTracer();

    $handler = function (RequestInterface $request, array $options) use ($alpn): PromiseInterface {
        $response = new Psr7Response(200, [], 'hello', '2');
        $options['on_stats'](new TransferStats($request, $response, 0.05, null, fledgeHandlerStats(['alpn' => $alpn])));

        return Create::promiseFor($response);
    };

    $parent = $tracer->startSpan('GET /');
    Http::setHandler($handler)->get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($span->getAttributes()->get('network.protocol.version'))->toBe('2');
})->with(['missing' => [null], 'unknown' => ['spdy/3'], 'empty' => ['']]);

it('omits connect and TLS times from a Fledge handler that does not report connection reuse', function () {
    [$tracer, $exporter] = httpTracer();

    $stats = fledgeHandlerStats();
    unset($stats['connection_reused']);

    $parent = $tracer->startSpan('GET /');
    Http::setHandler(httpStatsHandler($stats))->get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $attributes = $span->getAttributes();

    // Such a release repeats the original connect and TLS time on a reused connection.
    expect($attributes->has('fledge.http.connect_ms'))->toBeFalse()
        ->and($attributes->has('fledge.http.tls_ms'))->toBeFalse()
        ->and($attributes->has('fledge.http.connection_reused'))->toBeFalse()
        ->and($attributes->get('fledge.http.ttfb_ms'))->toBe(42.0)
        ->and($attributes->get('network.peer.address'))->toBe('192.0.2.10');
});

it('records nothing from empty handler stats', function () {
    [$tracer, $exporter] = httpTracer();

    // The Http::fake stub calls on_stats without handler stats.
    Http::fake(['*' => Http::response('ok')]);

    $parent = $tracer->startSpan('GET /');
    $response = Http::get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $keys = array_keys($span->getAttributes()->toArray());

    // No handler stats: the stub's Psr7 default 1.1 is not a measured version.
    expect($response->status())->toBe(200)
        ->and(array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'network.') || str_starts_with($key, 'fledge.http.') || $key === 'http.response.body.size')))->toBe([]);
});

it('records the stats of a failed connection before the span ends', function () {
    [$tracer, $exporter] = httpTracer();

    $stats = fledgeHandlerStats([
        'connect_time' => 0.0,
        'appconnect_time' => 0.0,
        'starttransfer_time' => 0.0,
        'size_download' => 0,
        'http_code' => 0,
        'alpn' => null,
    ]);

    $parent = $tracer->startSpan('GET /');

    expect(fn () => Http::setHandler(httpStatsHandler($stats, fail: true))->get('https://down.example.test/'))
        ->toThrow(ConnectionException::class);

    $parent->end();

    [$span] = httpSpans($tracer, $exporter);
    $attributes = $span->getAttributes();

    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($attributes->get('network.peer.address'))->toBe('192.0.2.10')
        ->and($attributes->get('fledge.http.connection_reused'))->toBeFalse()
        // No connection, no response: no connect or TLS time, no body size,
        // no time to first byte, no protocol.
        ->and($attributes->has('fledge.http.connect_ms'))->toBeFalse()
        ->and($attributes->has('fledge.http.tls_ms'))->toBeFalse()
        ->and($attributes->has('http.response.body.size'))->toBeFalse()
        ->and($attributes->has('fledge.http.ttfb_ms'))->toBeFalse()
        ->and($attributes->has('network.protocol.version'))->toBeFalse();
});

it('leaves a non-callable on_stats for the handler to reject', function (bool $timings) {
    config()->set('apm.options.http_client.timings', $timings);

    [$tracer] = httpTracer();

    $parent = $tracer->startSpan('GET /');

    try {
        expect(fn () => Http::setHandler(new MockHandler([new Psr7Response(200)]))
            ->send('GET', 'https://api.example.test/items', ['on_stats' => 'not a callable']))
            ->toThrow(InvalidArgumentException::class, 'Passing string to request option "on_stats" is invalid; expected callable.');
    } finally {
        $parent->end();
    }
})->with(['timings on' => [true], 'timings off' => [false]]);

it('still calls the user on_stats callback once with the same TransferStats', function () {
    [$tracer, $exporter] = httpTracer();
    $sent = null;
    $seen = [];

    $parent = $tracer->startSpan('GET /');
    $response = Http::setHandler(httpStatsHandler(fledgeHandlerStats(), $sent))
        ->withOptions(['on_stats' => function (TransferStats $stats) use (&$seen): void {
            $seen[] = $stats;
        }])
        ->get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBe($sent)
        ->and($response->transferStats)->toBe($sent)
        ->and($response->handlerStats())->toBe(fledgeHandlerStats())
        ->and($span->getAttributes()->get('fledge.http.ttfb_ms'))->toBe(42.0);
});

it('leaves on_stats untouched when timings are off', function () {
    config()->set('apm.options.http_client.timings', false);

    [$tracer, $exporter] = httpTracer();
    $callback = null;
    $seen = 0;

    $parent = $tracer->startSpan('GET /');
    Http::setHandler(httpStatsHandler(fledgeHandlerStats(), callback: $callback))
        ->withOptions(['on_stats' => function () use (&$seen): void {
            $seen++;
        }])
        ->get('https://api.example.test/items');
    $parent->end();

    [$span] = httpSpans($tracer, $exporter);

    // The handler got the framework's own callback, not a wrapper.
    expect($callback)->toBeInstanceOf(Closure::class)
        ->and((new ReflectionFunction($callback))->getClosureScopeClass()?->getName())->toBe(PendingRequest::class)
        ->and($seen)->toBe(1)
        ->and($span->getAttributes()->has('network.peer.address'))->toBeFalse()
        ->and($span->getAttributes()->has('fledge.http.ttfb_ms'))->toBeFalse();
});

it('wraps on_stats when timings are on', function () {
    [$tracer] = httpTracer();
    $callback = null;

    $parent = $tracer->startSpan('GET /');
    Http::setHandler(httpStatsHandler(fledgeHandlerStats(), callback: $callback))->get('https://api.example.test/items');
    $parent->end();

    expect((new ReflectionFunction($callback))->getClosureScopeClass()?->getName())->toBe(HttpClientInstrumentation::class);
});
