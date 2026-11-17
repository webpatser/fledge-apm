<?php

declare(strict_types=1);

namespace Fledge\Apm;

use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * A started span plus (optionally) the scope that made it the current span
 * of its fiber. end() ends the span and detaches the scope exactly once.
 *
 * Start and end it in the same fiber: the scope belongs to the fiber that
 * started the span.
 */
final class ActiveSpan
{
    private bool $ended = false;

    public function __construct(
        public readonly SpanInterface $span,
        private ?ScopeInterface $scope = null,
    ) {}

    /**
     * @param  non-empty-string  $key
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->span->setAttribute($key, $value);

        return $this;
    }

    /**
     * @param  iterable<non-empty-string, mixed>  $attributes
     */
    public function setAttributes(iterable $attributes): self
    {
        $this->span->setAttributes($attributes);

        return $this;
    }

    /**
     * Record the exception as a span event and mark the span as failed.
     *
     * Goes through ExceptionRecorder like every instrumentation: QueryException
     * bindings are redacted (unless `apm.options.database.record_bindings`),
     * and an exception another span or path already recorded only sets the
     * status here.
     *
     * @param  iterable<non-empty-string, mixed>  $attributes
     */
    public function recordException(Throwable $exception, iterable $attributes = []): self
    {
        ExceptionRecorder::record($this->span, $exception, $attributes);

        return $this;
    }

    public function setError(?string $description = null): self
    {
        $this->span->setStatus(StatusCode::STATUS_ERROR, $description);

        return $this;
    }

    public function isRecording(): bool
    {
        return $this->span->isRecording();
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    /**
     * End the span (optionally at an explicit epoch-nanosecond timestamp) and
     * restore the context that was current before it started.
     */
    public function end(?int $endEpochNanos = null): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;

        try {
            $this->span->end($endEpochNanos);
        } finally {
            $this->scope?->detach();
            $this->scope = null;
        }
    }
}
