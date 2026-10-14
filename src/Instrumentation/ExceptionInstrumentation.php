<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Tracer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use WeakMap;

/**
 * Records reported exceptions on the span that is active in the fiber that
 * reports them.
 *
 * Hooked through the exception handler's `reportable()` callbacks, which run
 * once per reported exception after `dontReport` filtering: an exception that
 * was handled (caught and passed to `report()`) is recorded without the
 * request failing, and Laravel's log channel is not a second source (a
 * MessageLogged listener would see the same exception again). Exceptions the
 * handler never reports (validation, 404s, auth) are not errors here either.
 *
 * What is recorded:
 * - an `exception` event on the active span with `exception.type`,
 *   `exception.message` and `exception.stacktrace`, and the span set to ERROR
 * - the local root span (the request, job or command transaction) set to
 *   ERROR, which makes the sampler's error-keeping processor export the whole
 *   trace even when the head sampler dropped it (see FiberBatchSpanProcessor)
 *
 * The event is added once per exception across all instrumentations (see
 * ExceptionRecorder); a QueryException is recorded without its bindings unless
 * `apm.options.database.record_bindings` is on.
 *
 * Limitations: a `reportable()` callback registered earlier in the app that
 * stops propagation (returns false) hides the exception from this one; an
 * exception reported with no active span is not recorded.
 */
final class ExceptionInstrumentation implements Instrumentation
{
    /** @var WeakMap<Throwable, true> */
    private WeakMap $recorded;

    public function register(Application $app, Tracer $tracer): void
    {
        if (! $tracer->enabled()) {
            return;
        }

        $handler = $app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $this->recorded = new WeakMap;

        $handler->reportable(function (Throwable $exception) use ($tracer): void {
            $this->record($tracer, $exception);
        });
    }

    private function record(Tracer $tracer, Throwable $exception): void
    {
        // The same exception can reach report() more than once (rethrown and
        // reported again further up).
        if (isset($this->recorded[$exception])) {
            return;
        }

        $this->recorded[$exception] = true;

        $span = $tracer->currentSpan();

        if (! $span->getContext()->isValid()) {
            return;
        }

        // Another path (the Livewire exception hook, a failed job) may have
        // recorded the event already; then only the status is set here.
        ExceptionRecorder::record($span, $exception, ['exception.escaped' => false]);

        $tracer->markRootFailed(ExceptionRecorder::message($exception));
    }
}
