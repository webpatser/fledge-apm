<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Closure;
use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ErrorType;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Instrumentation\Support\UrlRedaction;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Metrics\MetricsFactory;
use Fledge\Apm\Tracer;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use OpenTelemetry\API\Metrics\HistogramInterface;
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
 * - `record_full_path` (bool, default false): keep the URL path in `url.full`
 *   verbatim. By default credentials carried in the path (Telegram bot
 *   tokens, Slack and Discord webhook secrets, long random segments) are
 *   replaced by REDACTED.
 * - `timings` (bool, default true): wrap the request's `on_stats` option
 *   (chaining any callback already there; a non-callable value is left in
 *   place for the handler to reject) and copy the handler's transfer
 *   stats onto the span: peer address and port, protocol version, body size,
 *   connect, TLS and time-to-first-byte in milliseconds, connection reuse.
 *   When off, the request options are left untouched.
 *
 * Guzzle handlers (the Fledge handler, the curl handler, the Http::fake stub)
 * call `on_stats` before they settle the promise, on success and on failure,
 * so the attributes land before either promise callback ends the span.
 *
 * Requests outside any trace get no span and no trace headers.
 * The package's own OTLP exporter does not use the Http client, so exports
 * are never traced.
 *
 * Metrics (`apm.metrics.groups.http_client`): one `http.client.request.duration`
 * histogram record per request, in seconds, with `http.request.method`,
 * `server.address`, `server.port`, `http.response.status_code` (with a
 * response) and `error.type` (a failure: the exception class short name, or
 * the status code the span marks as an error). Never the URL or path.
 *
 * The record is made where the span ends (the promise callbacks), not in the
 * `on_stats` wrapper: `on_stats` is not called by every handler or path (a
 * handler that throws first, a non-callable `on_stats`) and sits behind the
 * `timings` option, while the metric must not depend on either. The clock is
 * hrtime from just before the span starts to the moment it ends, so metric and
 * span agree. It is recorded whether or not the span is sampled and for
 * requests outside any trace.
 */
final class HttpClientInstrumentation implements Instrumentation
{
    /** Semantic convention boundaries of `http.client.request.duration`, in seconds. */
    public const array DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    private const array KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH'];

    private ?HistogramInterface $duration = null;

    private bool $metrics = false;

    private bool $propagate = true;

    private bool $errorOn4xx = false;

    private bool $recordFullPath = false;

    private bool $timings = true;

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
        $this->recordFullPath = (bool) $app['config']->get('apm.options.http_client.record_full_path', false);
        $this->timings = (bool) $app['config']->get('apm.options.http_client.timings', true);
        $this->metrics = (bool) $app['config']->get('apm.metrics.groups.http_client', true);

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
        $traced = $tracer->currentSpan()->getContext()->isValid();
        $measured = $this->metrics && Metrics::enabled();

        if (! $traced && ! $measured) {
            return $handler($request, $options);
        }

        $started = hrtime(true);
        $uri = $request->getUri();
        $method = strtoupper($request->getMethod());
        $host = $uri->getHost();
        $port = $uri->getPort() ?? match ($uri->getScheme()) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        $span = null;

        if ($traced) {
            $span = $tracer->startSpan(
                name: trim("{$method} {$host}"),
                kind: SpanKind::KIND_CLIENT,
                attributes: array_filter([
                    'http.request.method' => $method,
                    'url.full' => $this->url($uri),
                    'server.address' => $host,
                    'server.port' => $port,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                activate: false,
            );

            if ($this->propagate) {
                $request = $this->inject($span, $request);
            }

            // A non-callable on_stats is left in place for the handler to reject,
            // exactly as it would without the instrumentation.
            if ($this->timings && (! isset($options['on_stats']) || is_callable($options['on_stats']))) {
                $options['on_stats'] = $this->onStats($span, $options['on_stats'] ?? null);
            }
        }

        $record = $measured
            ? function (?ResponseInterface $response, mixed $reason = null) use ($method, $host, $port, $started): void {
                $this->recordDuration($method, $host, $port, $started, $response, $reason);
            }
        : null;

        try {
            $promise = $handler($request, $options);
        } catch (Throwable $exception) {
            if ($span !== null) {
                $this->fail($span, $exception);
            }

            $record?->__invoke(null, $exception);

            throw $exception;
        }

        if (! $promise instanceof PromiseInterface) {
            $span?->end();
            $record?->__invoke($promise instanceof ResponseInterface ? $promise : null);

            return $promise;
        }

        return $promise->then(
            function (mixed $response) use ($span, $record): mixed {
                if ($span !== null) {
                    if ($response instanceof ResponseInterface) {
                        $this->respond($span, $response);
                    }

                    $span->end();
                }

                $record?->__invoke($response instanceof ResponseInterface ? $response : null);

                return $response;
            },
            function (mixed $reason) use ($span, $record): PromiseInterface {
                if ($span !== null) {
                    $this->fail($span, $reason);
                }

                $record?->__invoke(null, $reason);

                return Create::rejectionFor($reason);
            },
        );
    }

    /**
     * Record `http.client.request.duration` for one finished request. Never
     * throws: a metric must not turn a request into a failure.
     */
    private function recordDuration(string $method, string $host, ?int $port, int $started, ?ResponseInterface $response, mixed $reason): void
    {
        try {
            $seconds = (hrtime(true) - $started) / 1_000_000_000;

            if ($response === null && is_object($reason) && method_exists($reason, 'getResponse')) {
                $found = $reason->getResponse();
                $response = $found instanceof ResponseInterface ? $found : null;
            }

            $status = $response?->getStatusCode();
            $error = null;

            if ($status !== null) {
                // The status codes the span marks as errors.
                $error = $status >= 500 || ($status >= 400 && $this->errorOn4xx) ? (string) $status : null;
            } elseif ($reason instanceof Throwable) {
                $error = ErrorType::shortName($reason);
            } elseif ($reason !== null) {
                $error = ErrorType::OTHER;
            }

            $name = 'http.client.request.duration';

            $this->duration ??= Metrics::meter(MetricsFactory::INSTRUMENTATION_NAME)->createHistogram(
                $name,
                's',
                'Duration of outgoing HTTP requests.',
                ['ExplicitBucketBoundaries' => self::DURATION_BUCKETS],
            );

            $this->duration->record($seconds, Metrics::cardinality()->attributes($name, array_filter([
                'http.request.method' => in_array($method, self::KNOWN_METHODS, true) ? $method : '_OTHER',
                'server.address' => $host,
                'server.port' => $port,
                'http.response.status_code' => $status,
                'error.type' => $error,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')));
        } catch (Throwable) {
            // Instrumentation never breaks the request.
        }
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

    /**
     * An `on_stats` callback that records the transfer stats on the span and
     * then calls the callback the request already carried (the framework's
     * PendingRequest always sets one), with the same TransferStats.
     *
     * A Closure, because the Http::fake stub only calls Closure callbacks.
     */
    private function onStats(ActiveSpan $span, mixed $previous): Closure
    {
        return function (mixed $stats) use ($span, $previous): mixed {
            if ($stats instanceof TransferStats) {
                try {
                    $this->timings($span, $stats);
                } catch (Throwable) {
                    // Instrumentation never breaks the request.
                }
            }

            return is_callable($previous) ? $previous($stats) : null;
        };
    }

    /**
     * Copy the handler stats onto the span. Every key is optional: the stock
     * curl handler has no `connection_reused` or `alpn`, older Fledge handler
     * releases lack them too, and the Http::fake stub reports none at all.
     *
     * Timing conventions differ per handler. curl reports every time
     * cumulatively from the transfer start (connect_time up to the TCP
     * connect, appconnect_time up to the end of the TLS handshake). The
     * Fledge handler reports connect_time as the whole connect including TLS
     * and appconnect_time as the TLS handshake alone. Both are mapped to
     * connect_ms (connection ready, TLS included) and tls_ms (handshake only).
     *
     * connect_ms and tls_ms are omitted when they would mislead: on a Fledge
     * handler that does not report `connection_reused` (a reused connection
     * repeats its original connect and TLS time there), and on a failed
     * connection (connect_time 0 without a response), like ttfb_ms.
     *
     * network.protocol.version comes from `alpn`, or else from the response.
     */
    private function timings(ActiveSpan $span, TransferStats $stats): void
    {
        if ($span->hasEnded() || ! $span->isRecording()) {
            return;
        }

        $data = $stats->getHandlerStats();

        if (isset($data['primary_ip']) && is_string($data['primary_ip']) && $data['primary_ip'] !== '') {
            $span->setAttribute('network.peer.address', $data['primary_ip']);
        }

        if (isset($data['primary_port']) && is_numeric($data['primary_port']) && (int) $data['primary_port'] > 0) {
            $span->setAttribute('network.peer.port', (int) $data['primary_port']);
        }

        $version = isset($data['alpn']) && is_string($data['alpn']) ? match (strtolower($data['alpn'])) {
            'h2', 'h2c' => '2',
            'h3' => '3',
            'http/1.1' => '1.1',
            'http/1.0' => '1.0',
            default => null,
        } : null;

        if ($version === null && $data !== [] && $stats->hasResponse()) {
            $version = $stats->getResponse()?->getProtocolVersion();
        }

        if (is_string($version) && $version !== '') {
            $span->setAttribute('network.protocol.version', $version);
        }

        if ($stats->hasResponse() && isset($data['size_download']) && is_numeric($data['size_download'])) {
            $span->setAttribute('http.response.body.size', (int) $data['size_download']);
        }

        $connect = isset($data['connect_time']) && is_numeric($data['connect_time']) ? (float) $data['connect_time'] : null;
        $appconnect = isset($data['appconnect_time']) && is_numeric($data['appconnect_time']) ? (float) $data['appconnect_time'] : null;
        $fledge = ($data['handler'] ?? null) === 'fledge';

        if (($fledge && ! array_key_exists('connection_reused', $data)) || (($connect ?? 0.0) === 0.0 && ! $stats->hasResponse())) {
            $connect = $appconnect = null;
        }

        if ($connect !== null) {
            $ready = ! $fledge && $appconnect !== null && $appconnect > $connect ? $appconnect : $connect;

            $span->setAttribute('fledge.http.connect_ms', self::ms($ready));
        }

        if ($appconnect !== null) {
            $tls = $fledge ? $appconnect : ($appconnect > 0.0 && $connect !== null ? max(0.0, $appconnect - $connect) : 0.0);

            $span->setAttribute('fledge.http.tls_ms', self::ms($tls));
        }

        // 0 means no response byte arrived (a failed connection), not instant.
        if (isset($data['starttransfer_time']) && is_numeric($data['starttransfer_time']) && (float) $data['starttransfer_time'] > 0.0) {
            $span->setAttribute('fledge.http.ttfb_ms', self::ms((float) $data['starttransfer_time']));
        }

        if (isset($data['connection_reused']) && is_bool($data['connection_reused'])) {
            $span->setAttribute('fledge.http.connection_reused', $data['connection_reused']);
        }
    }

    private static function ms(float $seconds): float
    {
        return round($seconds * 1000, 3);
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
     * The request URL without user info and fragment, with the value of
     * credential-looking query parameters (token, key, secret, signature, ...)
     * replaced by REDACTED, and, unless `record_full_path` is on, with
     * credentials carried in the path (bot tokens, webhook secrets, long
     * random segments) replaced by REDACTED.
     */
    private function url(UriInterface $uri): string
    {
        $uri = $uri->withUserInfo('')->withQuery(UrlRedaction::query($uri->getQuery()))->withFragment('');

        if (! $this->recordFullPath) {
            $uri = $uri->withPath(UrlRedaction::clientPath($uri->getPath(), $uri->getHost()));
        }

        return (string) $uri;
    }
}
