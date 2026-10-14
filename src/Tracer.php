<?php

declare(strict_types=1);

namespace Fledge\Apm;

use Closure;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\ExportDeadline;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\LocalRootSpan;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

use function Fledge\Async\async;

/**
 * The span helper every instrumentation in this package goes through.
 *
 * - span(): run a closure inside a child span of the fiber's current span.
 * - startSpan() / ActiveSpan::end(): for start and end in different
 *   callbacks of the same fiber (kernel handled/terminating, job events).
 * - recordSpan(): an already finished span with explicit timestamps, such as
 *   a QueryExecuted span back-dated by `$event->time` (see nanosAgo()).
 *
 * Disabled mode wraps the OpenTelemetry NoopTracer: spans are non-recording
 * and nothing is buffered or exported.
 */
final class Tracer
{
    public const string INSTRUMENTATION_NAME = 'webpatser/fledge-apm';

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly FiberContextStorage $storage,
        private readonly ?TracerProviderInterface $provider = null,
        private readonly int $flushBudgetMs = 1500,
    ) {}

    public static function disabled(): self
    {
        return new self(NoopTracer::getInstance(), new FiberContextStorage);
    }

    public function enabled(): bool
    {
        return $this->provider !== null;
    }

    /**
     * Run the callback inside a new span that is the current span of this
     * fiber while the callback runs. An exception is recorded on the span,
     * marks it failed, and is rethrown.
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  Closure(ActiveSpan): T  $callback
     * @param  SpanKind::KIND_*  $kind
     * @param  iterable<non-empty-string, mixed>  $attributes
     * @return T
     */
    public function span(string $name, Closure $callback, int $kind = SpanKind::KIND_INTERNAL, iterable $attributes = []): mixed
    {
        $span = $this->startSpan($name, $kind, $attributes);

        try {
            return $callback($span);
        } catch (Throwable $exception) {
            $span->recordException($exception);

            throw $exception;
        } finally {
            $span->end();
        }
    }

    /**
     * Start a span as a child of the current span (or of `$parent`).
     *
     * With `$activate` it becomes the current span of this fiber until
     * ActiveSpan::end(), so spans started meanwhile nest under it.
     *
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  iterable<non-empty-string, mixed>  $attributes
     * @param  int|null  $startEpochNanos  back-dated start, see nanosAgo()
     * @param  ContextInterface|false|null  $parent  explicit parent context, false for a new root, null for the current context
     */
    public function startSpan(
        string $name,
        int $kind = SpanKind::KIND_INTERNAL,
        iterable $attributes = [],
        ?int $startEpochNanos = null,
        bool $activate = true,
        ContextInterface|false|null $parent = null,
    ): ActiveSpan {
        if ($this->provider === null) {
            return new ActiveSpan(Span::getInvalid());
        }

        $parent ??= $this->storage->current();

        $builder = $this->tracer->spanBuilder($name)
            ->setSpanKind($kind)
            ->setAttributes($attributes)
            ->setParent($parent);

        if ($startEpochNanos !== null) {
            $builder->setStartTimestamp($startEpochNanos);
        }

        $span = $builder->startSpan();

        if (! $activate) {
            return new ActiveSpan($span);
        }

        $context = $span->storeInContext($parent === false ? Context::getRoot() : $parent);

        // Attach through the storage, not Context::activate(), so the
        // assertion-only DebugScope wrapper never warns about scopes that a
        // reused Revolt fiber had cleared.
        return new ActiveSpan($span, $this->storage->attach($context));
    }

    /**
     * Record an already finished span under the current span.
     *
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  iterable<non-empty-string, mixed>  $attributes
     */
    public function recordSpan(
        string $name,
        int $startEpochNanos,
        ?int $endEpochNanos = null,
        int $kind = SpanKind::KIND_INTERNAL,
        iterable $attributes = [],
        ?Throwable $exception = null,
    ): void {
        if ($this->provider === null) {
            return;
        }

        $span = $this->startSpan($name, $kind, $attributes, $startEpochNanos, activate: false);

        if ($exception !== null) {
            $span->recordException($exception);
        }

        $span->end($endEpochNanos);
    }

    /**
     * The current span of this fiber (an invalid span when there is none).
     */
    public function currentSpan(): SpanInterface
    {
        return Span::fromContext($this->storage->current());
    }

    /**
     * The local root span (the request, job or command transaction) of this
     * fiber's current trace.
     */
    public function localRootSpan(): SpanInterface
    {
        return LocalRootSpan::fromContext($this->storage->current());
    }

    /**
     * Mark the local root span as failed, so the trace is kept even when the
     * sampler dropped it (see FiberBatchSpanProcessor).
     */
    public function markRootFailed(?string $description = null): void
    {
        $this->localRootSpan()->setStatus(StatusCode::STATUS_ERROR, $description);
    }

    public function storage(): FiberContextStorage
    {
        return $this->storage;
    }

    /**
     * Current time in epoch nanoseconds, on the clock spans use.
     */
    public static function now(): int
    {
        return Clock::getDefault()->now();
    }

    /**
     * Epoch nanoseconds `$milliseconds` ago, e.g. the start of a query from
     * QueryExecuted::$time.
     */
    public static function nanosAgo(float $milliseconds): int
    {
        return self::now() - (int) round($milliseconds * 1_000_000);
    }

    /**
     * Export everything buffered, in the calling fiber, within the flush
     * budget (`apm.batch.flush_budget_ms`).
     *
     * `$retry` allows retries of failed requests, still within the budget:
     * only for the final flush of a process (WorkerStopping, shutdown), never
     * for flushes that hold a worker which should be serving.
     */
    public function flush(bool $retry = false): bool
    {
        return $this->provider?->forceFlush(ExportDeadline::after($this->flushBudgetMs, $retry)) ?? true;
    }

    /**
     * Export everything buffered from a fresh fiber at the root context, so
     * the export is never part of a trace.
     *
     * With `$await` the calling fiber waits for the export: right for the
     * terminating callback, which runs after the response was sent (PHP-FPM
     * has no event loop left to run a detached fiber once the script ends).
     * The wait is bounded by the flush budget.
     */
    public function flushInBackground(bool $await = true, bool $retry = false): void
    {
        if ($this->provider === null) {
            return;
        }

        $future = async(function () use ($retry): bool {
            $scope = $this->storage->attach(Context::getRoot());

            try {
                return $this->flush($retry);
            } finally {
                $scope->detach();
            }
        });

        if ($await) {
            $future->await();
        } else {
            $future->ignore();
        }
    }

    public function shutdown(): bool
    {
        return $this->provider?->shutdown(ExportDeadline::after($this->flushBudgetMs, retry: true)) ?? true;
    }
}
