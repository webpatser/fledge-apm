<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Instrumentation\HttpInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * @return array{Tracer, InMemoryExporter, HttpInstrumentation, FiberContextStorage}
 */
function httpApm(): array
{
    [$tracer, $exporter, $storage] = memoryTracer();

    $instrumentation = new HttpInstrumentation;
    $instrumentation->register(app(), $tracer);

    return [$tracer, $exporter, $instrumentation, $storage];
}

/**
 * @return list<SpanDataInterface>
 */
function httpExported(Tracer $tracer, InMemoryExporter $exporter): array
{
    expect($tracer->flush())->toBeTrue();

    return $exporter->getSpans();
}

function httpSpan(array $spans, string $name): SpanDataInterface
{
    $matches = array_values(array_filter($spans, fn (SpanDataInterface $span): bool => $span->getName() === $name));

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

it('records one SERVER transaction named after the matched route with semconv attributes', function () {
    [$tracer, $exporter, , $storage] = httpApm();

    Route::get('/users/{id}', fn (string $id) => "user {$id}")->name('users.show');

    $this->get('/users/42', ['User-Agent' => 'apm-test'])->assertOk();

    $spans = httpExported($tracer, $exporter);
    $root = httpSpan($spans, 'GET /users/{id}');
    $attributes = $root->getAttributes();

    expect($spans)->toHaveCount(1)
        ->and($root->getKind())->toBe(SpanKind::KIND_SERVER)
        ->and($root->getParentContext()->isValid())->toBeFalse()
        ->and($root->hasEnded())->toBeTrue()
        ->and($root->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($attributes->get('http.request.method'))->toBe('GET')
        ->and($attributes->get('http.route'))->toBe('/users/{id}')
        ->and($attributes->get('url.path'))->toBe('/users/42')
        ->and($attributes->get('http.response.status_code'))->toBe(200)
        ->and($attributes->get('laravel.route.name'))->toBe('users.show')
        ->and($attributes->get('user_agent.original'))->toBe('apm-test')
        ->and($attributes->has('client.address'))->toBeFalse()
        ->and($attributes->has('url.query'))->toBeFalse()
        ->and($attributes->has('enduser.id'))->toBeFalse()
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse()
        ->and($storage->depth())->toBe(0);
});

it('names the root route as a slash', function () {
    [$tracer, $exporter] = httpApm();

    Route::post('/', fn () => 'home');

    $this->post('/')->assertOk();

    expect(httpSpan(httpExported($tracer, $exporter), 'POST /')->getAttributes()->get('http.route'))->toBe('/');
});

it('adds enduser.id when a user is authenticated', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/me', fn () => 'me');

    $this->actingAs((new User)->forceFill(['id' => 7]));
    $this->get('/me')->assertOk();

    expect(httpSpan(httpExported($tracer, $exporter), 'GET /me')->getAttributes()->get('enduser.id'))->toBe('7');
});

it('records the client ip only when record_client_ip is on', function () {
    config()->set('apm.http.record_client_ip', true);

    [$tracer, $exporter] = httpApm();

    Route::get('/ip', fn () => 'ip');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/ip')->assertOk();

    expect(httpSpan(httpExported($tracer, $exporter), 'GET /ip')->getAttributes()->get('client.address'))->toBe('203.0.113.9');
});

it('leaves out the user agent and user id when those toggles are off', function () {
    config()->set('apm.http.record_user_agent', false);
    config()->set('apm.http.record_user_id', false);

    [$tracer, $exporter] = httpApm();

    Route::get('/me', fn () => 'me');

    $this->actingAs((new User)->forceFill(['id' => 7]));
    $this->get('/me', ['User-Agent' => 'apm-test'])->assertOk();

    $attributes = httpSpan(httpExported($tracer, $exporter), 'GET /me')->getAttributes();

    expect($attributes->has('user_agent.original'))->toBeFalse()
        ->and($attributes->has('enduser.id'))->toBeFalse();
});

it('records url.query with secret-looking values redacted', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/search', fn () => 'results');

    $this->get('/search?q=surf&api_token=abc123&page=2')->assertOk();

    $query = httpSpan(httpExported($tracer, $exporter), 'GET /search')->getAttributes()->get('url.query');

    expect($query)->toContain('q=surf')
        ->toContain('page=2')
        ->toContain('api_token=REDACTED')
        ->not->toContain('abc123');
});

it('redacts credential-looking route parameters in url.path', function (string $uri, string $requested, string $recorded) {
    [$tracer, $exporter] = httpApm();

    Route::get($uri, fn () => 'ok');

    $this->get($requested)->assertOk();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET /'.ltrim($uri, '/'));

    expect($root->getAttributes()->get('url.path'))->toBe($recorded)
        ->and($root->getAttributes()->get('http.route'))->toBe('/'.ltrim($uri, '/'));
})->with([
    'reset token' => ['/reset-password/{token}', '/reset-password/s3cr3t-reset', '/reset-password/REDACTED'],
    'verify hash' => ['/email/verify/{id}/{hash}', '/email/verify/7/0a1b2c3d4e', '/email/verify/7/REDACTED'],
    'invite token' => ['/invite/{token}/accept', '/invite/abc123/accept', '/invite/REDACTED/accept'],
    'optional token' => ['/confirm/{token?}', '/confirm/abc123', '/confirm/REDACTED'],
    'embedded in segment' => ['/magic-{token}', '/magic-abc123', '/magic-REDACTED'],
    'harmless params stay' => ['/users/{id}/posts/{slug}', '/users/42/posts/hello-world', '/users/42/posts/hello-world'],
]);

it('leaves the url.path of a route without parameters alone', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/token-settings', fn () => 'ok');

    $this->get('/token-settings')->assertOk();

    expect(httpSpan(httpExported($tracer, $exporter), 'GET /token-settings')->getAttributes()->get('url.path'))
        ->toBe('/token-settings');
});

it('nests spans started during the request under the transaction', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/work', fn () => $tracer->span('inside', fn () => 'done', SpanKind::KIND_CLIENT));

    $this->get('/work')->assertOk();

    $spans = httpExported($tracer, $exporter);
    $root = httpSpan($spans, 'GET /work');
    $inside = httpSpan($spans, 'inside');

    expect($inside->getParentSpanId())->toBe($root->getSpanId())
        ->and($inside->getTraceId())->toBe($root->getTraceId());
});

it('marks a 5xx transaction as failed, records the exception and ends the span once', function () {
    [$tracer, $exporter, , $storage] = httpApm();

    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    $this->get('/boom')->assertServerError();

    $spans = httpExported($tracer, $exporter);
    $root = httpSpan($spans, 'GET /boom');
    $eventNames = array_map(fn ($event) => $event->getName(), $root->getEvents());

    expect($spans)->toHaveCount(1)
        ->and($root->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($root->getAttributes()->get('http.response.status_code'))->toBe(500)
        ->and($eventNames)->toContain('exception')
        ->and($storage->depth())->toBe(0);
});

it('does not mark a handled 4xx as an error', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/gone', fn () => abort(404));

    $this->get('/gone')->assertNotFound();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET /gone');

    expect($root->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($root->getAttributes()->get('http.response.status_code'))->toBe(404);
});

it('names an unmatched request without the high-cardinality path', function () {
    [$tracer, $exporter] = httpApm();

    $this->get('/no/such/page')->assertNotFound();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET (unmatched)');

    expect($root->getAttributes()->get('url.path'))->toBe('/no/such/page')
        ->and($root->getAttributes()->has('http.route'))->toBeFalse();
});

it('continues an incoming W3C traceparent', function () {
    [$tracer, $exporter] = httpApm();

    $traceId = str_repeat('ab', 16);
    $parentId = str_repeat('cd', 8);

    Route::get('/traced', fn () => 'ok');

    $this->get('/traced', ['traceparent' => "00-{$traceId}-{$parentId}-01"])->assertOk();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET /traced');

    expect($root->getTraceId())->toBe($traceId)
        ->and($root->getParentSpanId())->toBe($parentId)
        ->and($root->getParentContext()->isRemote())->toBeTrue();
});

/**
 * The context the route saw, for a request with the given traceparent,
 * under a tracer that samples nothing and keeps nothing on its own.
 */
function contextSeenFor(string $traceparent): SpanContextInterface
{
    config()->set('apm.sample_rate', 0.0);

    [$tracer] = memoryTracer(['sample_rate' => 0.0, 'keep' => ['errors' => false, 'slow_ms' => 0]]);
    (new HttpInstrumentation)->register(app(), $tracer);

    $seen = null;

    Route::get('/traced', function () use ($tracer, &$seen) {
        $seen = $tracer->currentSpan()->getContext();

        return 'ok';
    });

    test()->get('/traced', ['traceparent' => $traceparent])->assertOk();

    return $seen;
}

it('continues the trace id but samples locally when the caller says sampled', function () {
    $traceId = str_repeat('ab', 16);

    $seen = contextSeenFor("00-{$traceId}-".str_repeat('cd', 8).'-01');

    expect($seen->getTraceId())->toBe($traceId)
        ->and($seen->isSampled())->toBeFalse();
});

it('lets the caller force sampling when honor_sampled_flag is on', function () {
    config()->set('apm.http.honor_sampled_flag', true);

    $traceId = str_repeat('ab', 16);

    $seen = contextSeenFor("00-{$traceId}-".str_repeat('cd', 8).'-01');

    expect($seen->getTraceId())->toBe($traceId)
        ->and($seen->isSampled())->toBeTrue();
});

it('samples a continued trace locally even when the caller says not sampled', function () {
    [$tracer, $exporter] = httpApm();

    $traceId = str_repeat('ab', 16);
    $parentId = str_repeat('cd', 8);

    Route::get('/traced', fn () => 'ok');

    // apm.sample_rate defaults to 1.0: the local decision keeps it.
    $this->get('/traced', ['traceparent' => "00-{$traceId}-{$parentId}-00"])->assertOk();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET /traced');

    expect($root->getTraceId())->toBe($traceId)
        ->and($root->getParentSpanId())->toBe($parentId);
});

it('starts a new trace for an incoming traceparent when trust_traceparent is off', function () {
    config()->set('apm.http.trust_traceparent', false);

    [$tracer, $exporter] = httpApm();

    Route::get('/traced', fn () => 'ok');

    $this->get('/traced', ['traceparent' => '00-'.str_repeat('ab', 16).'-'.str_repeat('cd', 8).'-01'])->assertOk();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET /traced');

    expect($root->getParentContext()->isValid())->toBeFalse()
        ->and($root->getTraceId())->not->toBe(str_repeat('ab', 16));
});

it('starts a new trace when the traceparent is malformed', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/traced', fn () => 'ok');

    $this->get('/traced', ['traceparent' => 'not-a-traceparent'])->assertOk();

    $root = httpSpan(httpExported($tracer, $exporter), 'GET /traced');

    expect($root->getParentContext()->isValid())->toBeFalse();
});

it('does not link two requests into one trace', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/a', fn () => 'a');

    $this->get('/a')->assertOk();
    $this->get('/a')->assertOk();

    $spans = httpExported($tracer, $exporter);

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->getTraceId())->not->toBe($spans[1]->getTraceId());
});

it('still records the transaction when the middleware is skipped', function () {
    [$tracer, $exporter] = httpApm();

    Route::get('/ping', fn () => 'pong');

    $this->withoutMiddleware();
    $this->get('/ping')->assertOk();

    $spans = httpExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('GET /ping');
});

it('ends an open transaction on terminating when RequestHandled never fired', function () {
    [$tracer, $exporter, $instrumentation, $storage] = httpApm();

    $request = Request::create('/orphan', 'GET');

    $instrumentation->handle($request, fn () => response('x'));

    expect($storage->depth())->toBe(1);

    event(new Terminating);
    event(new Terminating);

    $spans = httpExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getKind())->toBe(SpanKind::KIND_SERVER)
        ->and($storage->depth())->toBe(0);
});
