<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Closure;
use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Instrumentation\Support\UrlRedaction;
use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplingResult;
use Throwable;
use WeakMap;

/**
 * One SERVER transaction per HTTP request.
 *
 * The span starts in a global middleware that is prepended to the HTTP kernel
 * (back-dated to REQUEST_TIME_FLOAT, so it also covers the framework boot) and
 * ends on RequestHandled, when the final response, the matched route and the
 * authenticated user are known. A Terminating listener ends anything that is
 * still open, and ActiveSpan::end() is idempotent, so the span is ended
 * exactly once, also when an exception escapes the pipeline (the kernel
 * renders it and still dispatches RequestHandled).
 *
 * An incoming W3C `traceparent` header continues the caller's trace (see
 * incomingContext() for the sampled flag).
 *
 * Options (all under `apm.http`):
 * - `trust_traceparent` (bool, default true): continue incoming trace ids.
 * - `honor_sampled_flag` (bool, default false): let the caller's sampled flag
 *   decide instead of the local sample rate.
 * - `record_client_ip` (bool, default false): `client.address`.
 * - `record_user_agent` (bool, default true): `user_agent.original`.
 * - `record_user_id` (bool, default true): `enduser.id`.
 *
 * `url.query` is recorded with credential-looking values redacted, and so are
 * the credential-looking parameters of the matched route in `url.path`
 * (`/reset-password/{token}` is recorded as `/reset-password/REDACTED`).
 */
final class HttpInstrumentation implements Instrumentation
{
    /** @var WeakMap<Request, ActiveSpan> */
    private WeakMap $open;

    /** @var WeakMap<Request, Throwable> */
    private WeakMap $exceptions;

    private ?Application $app = null;

    private ?Tracer $tracer = null;

    public function __construct()
    {
        $this->open = new WeakMap;
        $this->exceptions = new WeakMap;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->app = $app;
        $this->tracer = $tracer;

        // The kernel resolves the middleware from the container: hand it this instance.
        $app->instance(self::class, $this);

        $events = $app->make('events');
        $events->listen(RequestHandled::class, $this->finish(...));
        $events->listen(Terminating::class, $this->abandon(...));

        if ($app->runningInConsole() && ! $app->runningUnitTests()) {
            return;
        }

        if ($app->bound(HttpKernel::class)) {
            $kernel = $app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(self::class);
            }
        }
    }

    /**
     * Global middleware entry point: starts the transaction.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $this->begin($request);

        try {
            return $next($request);
        } catch (Throwable $exception) {
            // The kernel renders it and dispatches RequestHandled, which ends the span.
            $this->exceptions[$request] = $exception;

            throw $exception;
        }
    }

    private function begin(Request $request): ActiveSpan
    {
        if (isset($this->open[$request])) {
            return $this->open[$request];
        }

        $method = $request->getMethod();

        $attributes = array_filter([
            'http.request.method' => $method,
            'url.path' => '/'.ltrim($request->getPathInfo(), '/'),
            'url.query' => UrlRedaction::query((string) $request->getQueryString()),
            'url.scheme' => $request->getScheme(),
            'server.address' => $request->getHost(),
            'user_agent.original' => $this->option('record_user_agent', true) ? $request->userAgent() : null,
            'client.address' => $this->option('record_client_ip', false) ? $request->ip() : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->open[$request] = $this->tracer->startSpan(
            $method,
            SpanKind::KIND_SERVER,
            $attributes,
            $this->requestStart($request),
            parent: $this->incomingContext($request) ?? false,
        );
    }

    private function finish(RequestHandled $event): void
    {
        $request = $event->request;

        // Without the middleware (middleware disabled) the transaction is created after the fact.
        $active = $this->open[$request] ?? $this->begin($request);
        $exception = $this->exceptions[$request] ?? null;

        unset($this->open[$request], $this->exceptions[$request]);

        try {
            $this->describe($active, $request, $event->response, $exception);
        } finally {
            $active->end();
        }
    }

    private function describe(ActiveSpan $active, Request $request, mixed $response, ?Throwable $exception): void
    {
        if (! $active->isRecording()) {
            return;
        }

        $method = $request->getMethod();
        $route = $request->route();

        if ($route instanceof Route) {
            $uri = '/'.ltrim($route->uri(), '/');

            $active->span->updateName("{$method} {$uri}");
            $active->setAttribute('http.route', $uri);
            $active->setAttribute('url.path', UrlRedaction::path('/'.ltrim($request->getPathInfo(), '/'), $route->uri()));

            if (($name = $route->getName()) !== null) {
                $active->setAttribute('laravel.route.name', $name);
            }
        } else {
            $active->span->updateName("{$method} (unmatched)");
        }

        if ($this->option('record_user_id', true) && ($userId = $this->userId()) !== null) {
            $active->setAttribute('enduser.id', $userId);
        }

        $status = is_object($response) && method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : null;

        if ($status !== null) {
            $active->setAttribute('http.response.status_code', $status);
        }

        if ($status !== null && $status >= 500) {
            // The router's pipeline usually renders the exception before it reaches
            // the middleware; the exception handler leaves it on the response.
            $exception ??= $response->exception ?? null;

            if ($exception instanceof Throwable) {
                // The exception handler may have recorded it on this span already.
                ExceptionRecorder::record($active->span, $exception);
            } else {
                $active->setError("HTTP {$status}");
            }
        }
    }

    /**
     * Safety net: end whatever is still open when the app terminates.
     */
    private function abandon(): void
    {
        $open = [];

        foreach ($this->open as $request => $active) {
            $open[] = [$request, $active];
        }

        foreach ($open as [$request, $active]) {
            unset($this->open[$request], $this->exceptions[$request]);

            $active->end();
        }
    }

    /**
     * The authenticated user's id, only when a user was already resolved: no
     * session or database lookup happens here.
     */
    private function userId(): ?string
    {
        try {
            $guard = $this->app->make('auth')->guard();

            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                return null;
            }

            $id = $guard->id();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The remote parent from a valid W3C `traceparent` header, if any.
     *
     * The trace id is continued (unless `apm.http.trust_traceparent` is off),
     * but the caller's sampled flag only decides with
     * `apm.http.honor_sampled_flag`: otherwise the local sample rate decides
     * for the continued trace id, so a public caller cannot force (or
     * suppress) sampling with a hand-made header.
     */
    private function incomingContext(Request $request): ?ContextInterface
    {
        if (! $this->option('trust_traceparent', true)) {
            return null;
        }

        $traceparent = $request->headers->get('traceparent');

        if (! is_string($traceparent) || $traceparent === '') {
            return null;
        }

        $carrier = ['traceparent' => $traceparent];

        if (is_string($tracestate = $request->headers->get('tracestate'))) {
            $carrier['tracestate'] = $tracestate;
        }

        $context = TraceContextPropagator::getInstance()->extract($carrier, null, Context::getRoot());
        $remote = Span::fromContext($context)->getContext();

        if (! $remote->isValid()) {
            return null;
        }

        if ($this->option('honor_sampled_flag', false)) {
            return $context;
        }

        $local = SpanContext::createFromRemoteParent(
            $remote->getTraceId(),
            $remote->getSpanId(),
            $this->sampledLocally($remote->getTraceId()) ? TraceFlags::SAMPLED : TraceFlags::DEFAULT,
            $remote->getTraceState(),
        );

        return Span::wrap($local)->storeInContext(Context::getRoot());
    }

    /**
     * The local head-sampling decision (`apm.sample_rate`) for a trace id, as
     * the ratio sampler would take it for a new trace.
     */
    private function sampledLocally(string $traceId): bool
    {
        $ratio = max(0.0, min(1.0, (float) $this->app['config']->get('apm.sample_rate', 1.0)));

        if ($ratio >= 1.0) {
            return true;
        }

        if ($ratio <= 0.0) {
            return false;
        }

        $result = new TraceIdRatioBasedSampler($ratio)->shouldSample(
            Context::getRoot(),
            $traceId,
            'http',
            SpanKind::KIND_SERVER,
            Attributes::create([]),
            [],
        );

        return $result->getDecision() === SamplingResult::RECORD_AND_SAMPLE;
    }

    /**
     * An `apm.http.*` option.
     */
    private function option(string $key, bool $default): bool
    {
        return (bool) $this->app['config']->get("apm.http.{$key}", $default);
    }

    /**
     * Back-date the transaction to when PHP received the request, so it
     * includes the framework boot. Ignored when the value is missing or
     * implausible (a long-running process reusing a stale timestamp).
     */
    private function requestStart(Request $request): ?int
    {
        $started = $request->server->get('REQUEST_TIME_FLOAT');

        if (! is_float($started) && ! is_int($started)) {
            return null;
        }

        $elapsedMs = (microtime(true) - (float) $started) * 1000;

        return $elapsedMs >= 0 && $elapsedMs < 30_000 ? Tracer::nanosAgo($elapsedMs) : null;
    }
}
