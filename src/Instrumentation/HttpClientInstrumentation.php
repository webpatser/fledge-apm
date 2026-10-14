<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Instrumentation\Support\UrlRedaction;
use Fledge\Apm\Tracer;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;
use WeakMap;

/**
 * One CLIENT span per outgoing request of the Http client, plus W3C
 * `traceparent` (and `tracestate`) injection so downstream services join the
 * trace.
 *
 * Implemented as Http client global middleware instead of listening to
 * RequestSending / ResponseReceived / ConnectionFailed:
 * - The span has to exist before the request leaves, to inject the span's own
 *   traceparent. RequestSending fires after global middleware ran and carries
 *   an Illuminate Request wrapper that is not the object that fails later.
 * - ConnectionFailed receives a different Request object than RequestSending
 *   (Guzzle's prepare_body and cookie middleware replace the PSR request in
 *   between), so events cannot be correlated by request identity.
 * - The middleware keeps the span in its own closure: state per request, never
 *   a shared "current request", and every concurrent request in a pool or in
 *   parallel fibers is its own span under the parent captured when it was sent.
 *
 * The span is not activated (nothing nests under an HTTP call) and may end in
 * a different fiber than it started in.
 *
 * Options (all under `apm.options.http_client`):
 * - `propagate` (bool, default true): inject trace context headers.
 * - `error_on_4xx` (bool, default false): also mark 4xx responses as ERROR.
 *   By default only 5xx responses and connection failures are errors; a 404
 *   from an API is usually an answer, not a failure of this service.
 *
 * Requests outside any trace are neither recorded nor given trace headers.
 * The package's own OTLP exporter does not use the Http client, so exports
 * are never traced.
 */
final class HttpClientInstrumentation implements Instrumentation
{
    private bool $propagate = true;

    private bool $errorOn4xx = false;

    /** @var WeakMap<Factory, true> */
    private WeakMap $patched;

    public function __construct()
    {
        $this->patched = new WeakMap;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->propagate = (bool) $app['config']->get('apm.options.http_client.propagate', true);
        $this->errorOn4xx = (bool) $app['config']->get('apm.options.http_client.error_on_4xx', false);

        $install = function (Factory $factory) use ($tracer): void {
            if (isset($this->patched[$factory])) {
                return;
            }

            $this->patched[$factory] = true;

            $factory->globalMiddleware(
                fn (callable $handler): callable => fn (RequestInterface $request, array $options): mixed => $this->handle($tracer, $handler, $request, $options),
            );
        };

        if ($app->resolved(Factory::class)) {
            $install($app->make(Factory::class));
        }

        $app->afterResolving(Factory::class, $install);
    }

    /**
     * @param  callable(RequestInterface, array<string, mixed>): mixed  $handler
     * @param  array<string, mixed>  $options
     */
    private function handle(Tracer $tracer, callable $handler, RequestInterface $request, array $options): mixed
    {
        if (! $tracer->currentSpan()->getContext()->isValid()) {
            return $handler($request, $options);
        }

        $uri = $request->getUri();
        $method = strtoupper($request->getMethod());
        $host = $uri->getHost();

        $span = $tracer->startSpan(
            name: trim("{$method} {$host}"),
            kind: SpanKind::KIND_CLIENT,
            attributes: array_filter([
                'http.request.method' => $method,
                'url.full' => self::url($uri),
                'server.address' => $host,
                'server.port' => $uri->getPort() ?? match ($uri->getScheme()) {
                    'https' => 443,
                    'http' => 80,
                    default => null,
                },
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            activate: false,
        );

        if ($this->propagate) {
            $request = $this->inject($span, $request);
        }

        try {
            $promise = $handler($request, $options);
        } catch (Throwable $exception) {
            $this->fail($span, $exception);

            throw $exception;
        }

        if (! $promise instanceof PromiseInterface) {
            $span->end();

            return $promise;
        }

        return $promise->then(
            function (mixed $response) use ($span): mixed {
                if ($response instanceof ResponseInterface) {
                    $this->respond($span, $response);
                }

                $span->end();

                return $response;
            },
            function (mixed $reason) use ($span): PromiseInterface {
                $this->fail($span, $reason);

                return Create::rejectionFor($reason);
            },
        );
    }

    private function inject(ActiveSpan $span, RequestInterface $request): RequestInterface
    {
        if (! $span->span->getContext()->isValid()) {
            return $request;
        }

        $carrier = [];

        TraceContextPropagator::getInstance()->inject($carrier, null, $span->span->storeInContext(Context::getRoot()));

        foreach ($carrier as $name => $value) {
            // A header the caller set explicitly (a proxy, a hand-made id) wins.
            if (! $request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }

        return $request;
    }

    private function respond(ActiveSpan $span, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        $span->setAttribute('http.response.status_code', $status);

        if ($status >= 500 || ($status >= 400 && $this->errorOn4xx)) {
            $span->setAttribute('error.type', (string) $status);
            $span->setError("HTTP {$status}");
        }
    }

    private function fail(ActiveSpan $span, mixed $reason): void
    {
        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;

        if ($response instanceof ResponseInterface) {
            // A rejection carrying a response (Guzzle http_errors): the status
            // decides, like for a resolved response.
            $this->respond($span, $response);
        } elseif ($reason instanceof Throwable) {
            $span->setAttribute('error.type', $reason::class);
            ExceptionRecorder::record($span->span, $reason);
        } else {
            $span->setError('request failed');
        }

        $span->end();
    }

    /**
     * The request URL without user info and fragment, and with the value of
     * credential-looking query parameters (token, key, secret, signature, ...)
     * replaced by REDACTED.
     */
    private static function url(UriInterface $uri): string
    {
        return (string) $uri->withUserInfo('')->withQuery(UrlRedaction::query($uri->getQuery()))->withFragment('');
    }
}
