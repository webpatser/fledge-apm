<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;
use WeakMap;

/**
 * One CONSUMER transaction per processed job, and trace propagation across
 * the queue.
 *
 * Dispatch: a `Queue::createPayloadUsing` hook writes the current W3C
 * `traceparent` (and `tracestate`) into the job payload. Worker: JobProcessing
 * starts the transaction as a child of that remote parent (or as a new root
 * when the payload carries none, or as a local child when the job runs inside
 * the dispatching trace, see parent()); JobProcessed, JobFailed and
 * JobExceptionOccurred end it. Whichever arrives first ends the span and the
 * others are no-ops, so it is ended exactly once per attempt.
 */
final class QueueInstrumentation implements Instrumentation
{
    /** The tracer the payload hook injects from; older hooks (tests, reloads) go quiet. */
    private static ?Tracer $injecting = null;

    /** @var WeakMap<Job, ActiveSpan> */
    private WeakMap $open;

    private ?Tracer $tracer = null;

    public function __construct()
    {
        $this->open = new WeakMap;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->tracer = $tracer;

        self::$injecting = $tracer;

        Queue::createPayloadUsing(static function () use ($tracer): array {
            if (self::$injecting !== $tracer) {
                return [];
            }

            $carrier = [];

            TraceContextPropagator::getInstance()->inject($carrier, null, $tracer->storage()->current());

            return $carrier;
        });

        $events = $app->make('events');
        $events->listen(JobProcessing::class, $this->start(...));
        $events->listen(JobProcessed::class, $this->processed(...));
        $events->listen(JobFailed::class, $this->failed(...));
        $events->listen(JobExceptionOccurred::class, $this->failed(...));
    }

    private function start(JobProcessing $event): void
    {
        $job = $event->job;

        // A re-fired event for a job that is still open keeps the first span.
        if (isset($this->open[$job])) {
            return;
        }

        $payload = $this->payload($job);
        $name = $this->displayName($job, $payload);

        $attributes = array_filter([
            'messaging.system' => 'laravel-queue',
            'messaging.operation.type' => 'process',
            'messaging.destination.name' => $job->getQueue(),
            'messaging.message.id' => $job->getJobId(),
            'laravel.queue.connection' => $event->connectionName,
            'laravel.job.name' => $job->resolveName(),
            'laravel.job.attempts' => $job->attempts(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $this->open[$job] = $this->tracer->startSpan(
            $name,
            SpanKind::KIND_CONSUMER,
            $attributes,
            parent: $this->parent($payload),
        );
    }

    /**
     * The parent of a job transaction:
     * - the current span, when the job runs in the process that dispatched it
     *   (sync queue, inside the dispatching trace): a local child, so the
     *   sampler and the error-keeping processor treat it as part of that
     *   trace instead of as a separate local root under a remote parent
     * - the remote parent from the payload otherwise
     * - a new root (false) when the payload carries no trace context
     *
     * @param  array<string, mixed>  $payload
     */
    private function parent(array $payload): ContextInterface|false|null
    {
        $remote = $this->remoteParent($payload);

        if ($remote === null) {
            return false;
        }

        $current = $this->tracer->currentSpan()->getContext();

        if ($current->isValid() && $current->getTraceId() === Span::fromContext($remote)->getContext()->getTraceId()) {
            return null;
        }

        return $remote;
    }

    private function processed(JobProcessed $event): void
    {
        $this->end($event->job, null);
    }

    private function failed(JobFailed|JobExceptionOccurred $event): void
    {
        $this->end($event->job, $event->exception);
    }

    private function end(Job $job, ?Throwable $exception): void
    {
        $active = $this->open[$job] ?? null;

        if ($active === null) {
            return;
        }

        unset($this->open[$job]);

        try {
            if ($exception !== null) {
                // The worker reports it afterwards; the event is added only once.
                ExceptionRecorder::record($active->span, $exception);
            }
        } finally {
            $active->end();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Job $job): array
    {
        try {
            $payload = $job->payload();
        } catch (Throwable) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return non-empty-string
     */
    private function displayName(Job $job, array $payload): string
    {
        $name = $payload['displayName'] ?? null;

        if (! is_string($name) || $name === '') {
            $name = $job->resolveName();
        }

        return is_string($name) && $name !== '' ? $name : 'queue job';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remoteParent(array $payload): ?ContextInterface
    {
        $traceparent = $payload['traceparent'] ?? null;

        if (! is_string($traceparent) || $traceparent === '') {
            return null;
        }

        $carrier = ['traceparent' => $traceparent];

        if (is_string($tracestate = $payload['tracestate'] ?? null)) {
            $carrier['tracestate'] = $tracestate;
        }

        $context = TraceContextPropagator::getInstance()->extract($carrier, null, Context::getRoot());

        return Span::fromContext($context)->getContext()->isValid() ? $context : null;
    }
}
