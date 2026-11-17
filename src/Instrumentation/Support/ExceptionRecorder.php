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
 *   message plus the SQL with its `?` placeholders. This also holds for a
 *   QueryException wrapped by another exception (a ViewException carries its
 *   message, the stack trace its `Caused by` line).
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
        $replacements = self::replacements($exception);

        return $replacements === [] ? $exception->getMessage() : strtr($exception->getMessage(), $replacements);
    }

    /**
     * Event attributes overriding the SDK defaults when the message or the
     * stack trace (its `Caused by` chain) had to be redacted.
     *
     * @return array<non-empty-string, string>
     */
    private static function attributes(Throwable $exception): array
    {
        $message = self::message($exception);
        $replacements = self::replacements($exception);

        if ($replacements === [] && $message === $exception->getMessage()) {
            return [];
        }

        $trace = StackTraceFormatter::format($exception);

        return [
            'exception.message' => $message,
            'exception.stacktrace' => $replacements === [] ? $trace : strtr($trace, $replacements),
        ];
    }

    /**
     * The interpolated message of every QueryException in the chain (the
     * exception itself and its previous exceptions, such as the QueryException
     * a ViewException wraps) mapped to its placeholder form. Empty when
     * bindings may be recorded or the chain holds no QueryException.
     *
     * Built from the innermost exception outwards, so a QueryException whose
     * driver message is itself a QueryException (or a wrapper around one)
     * gets that inner message redacted too.
     *
     * @return array<string, string>
     */
    private static function replacements(Throwable $exception): array
    {
        $chain = [];
        $hasQuery = false;

        for ($current = $exception; $current !== null && ! isset($chain[spl_object_id($current)]); $current = $current->getPrevious()) {
            $chain[spl_object_id($current)] = $current;
            $hasQuery = $hasQuery || $current instanceof QueryException;
        }

        if (! $hasQuery || self::recordBindings()) {
            return [];
        }

        $replacements = [];

        foreach (array_reverse($chain) as $current) {
            if (! $current instanceof QueryException) {
                continue;
            }

            $original = $current->getMessage();
            $redacted = self::redactedQueryMessage($current, $replacements);

            if ($original !== '' && $original !== $redacted) {
                $replacements[$original] = $redacted;
            }
        }

        return $replacements;
    }

    /**
     * @param  array<string, string>  $inner  replacements for the exceptions below this one
     */
    private static function redactedQueryMessage(QueryException $exception, array $inner): string
    {
        $driver = $exception->getPrevious()?->getMessage();

        if ($driver === null || $driver === '') {
            $driver = 'SQLSTATE['.$exception->getCode().']';
        } elseif ($inner !== []) {
            $driver = strtr($driver, $inner);
        }

        return sprintf('%s (Connection: %s, SQL: %s)', $driver, $exception->getConnectionName(), $exception->getSql());
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
