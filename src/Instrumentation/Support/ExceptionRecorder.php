<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation\Support;

use Illuminate\Database\QueryException;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Exception\StackTraceFormatter;
use Throwable;
use WeakMap;

/**
 * The one place instrumentations record exceptions through.
 *
 * - Dedupe: several paths see the same exception (the Livewire `exception`
 *   hook, the exception handler's `reportable()`, the HTTP 5xx response, a
 *   failed job, a failed HTTP client call). The first path adds the
 *   `exception` event; later paths only set the span status. The marker is a
 *   WeakMap keyed by the exception, shared by every instrumentation.
 * - Privacy: a QueryException message interpolates the bindings into the SQL.
 *   Unless `apm.options.database.record_bindings` is on, the recorded message
 *   (event message, stack trace header and status description) is the driver
 *   message plus the SQL with its `?` placeholders.
 *
 * @internal
 */
final class ExceptionRecorder
{
    /** @var WeakMap<Throwable, true>|null */
    private static ?WeakMap $recorded = null;

    /**
     * Mark the exception as recorded. True only for the first caller.
     */
    public static function claim(Throwable $exception): bool
    {
        self::$recorded ??= new WeakMap;

        if (isset(self::$recorded[$exception])) {
            return false;
        }

        self::$recorded[$exception] = true;

        return true;
    }

    /**
     * Set the span to ERROR and, unless another path already did, add the
     * `exception` event. Returns whether the event was added here.
     *
     * @param  iterable<non-empty-string, mixed>  $attributes
     */
    public static function record(SpanInterface $span, Throwable $exception, iterable $attributes = []): bool
    {
        $span->setStatus(StatusCode::STATUS_ERROR, self::message($exception));

        if (! self::claim($exception)) {
            return false;
        }

        $event = self::attributes($exception);

        foreach ($attributes as $key => $value) {
            $event[$key] = $value;
        }

        $span->recordException($exception, $event);

        return true;
    }

    /**
     * The exception message as it may be exported.
     */
    public static function message(Throwable $exception): string
    {
        if (! $exception instanceof QueryException || self::recordBindings()) {
            return $exception->getMessage();
        }

        $driver = $exception->getPrevious()?->getMessage();

        if ($driver === null || $driver === '') {
            $driver = 'SQLSTATE['.$exception->getCode().']';
        }

        return sprintf('%s (Connection: %s, SQL: %s)', $driver, $exception->getConnectionName(), $exception->getSql());
    }

    /**
     * Event attributes overriding the SDK defaults when the message had to be
     * redacted.
     *
     * @return array<non-empty-string, string>
     */
    private static function attributes(Throwable $exception): array
    {
        $message = self::message($exception);
        $original = $exception->getMessage();

        if ($message === $original) {
            return [];
        }

        $trace = StackTraceFormatter::format($exception);

        return [
            'exception.message' => $message,
            'exception.stacktrace' => $original === '' ? $trace : str_replace($original, $message, $trace),
        ];
    }

    private static function recordBindings(): bool
    {
        try {
            return (bool) app('config')->get('apm.options.database.record_bindings', false);
        } catch (Throwable) {
            return false;
        }
    }
}
