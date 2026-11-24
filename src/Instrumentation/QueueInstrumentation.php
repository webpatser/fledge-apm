<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fiber;
use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * One PRODUCER span per dispatch, one CONSUMER transaction per processed
 * job, and trace propagation across the queue.
 *
 * Dispatch: a `Queue::createPayloadUsing` hook adds `apmQueuedAt` (epoch
 * seconds, float) and the W3C `traceparent` (and `tracestate`) to the job
 * payload. Inside an existing trace (sampled or not) it also starts a
 * PRODUCER span `send {queue}` and injects that span's context instead of the
 * current one; JobQueued ends it. A dispatch outside any trace starts no span,
 * as before. `apm.options.queue.producer_spans` (default true) switches the
 * producer span off; `apmQueuedAt` is added either way.
 *
 * Producer spans whose JobQueued never arrives are swept at the terminating
 * callback and on Looping, once their dispatching fiber is the one wrapping up
 * or has terminated, or once they are older than LEFTOVER_GRACE_NANOS. They
 * end without error status and with `fledge.queue.unconfirmed` set to true:
 * a database `bulk()` (and so a bus batch on the database driver) pushes
 * without firing JobQueued, while a push that really fails throws to its
 * caller. An unconfirmed span lasts until the sweep runs, so its duration is
 * not the push time. At most MAX_OPEN_PRODUCERS are open at once; dispatches
 * beyond that inject the current context instead.
 *
 * Worker: JobProcessing starts the transaction as a child of that remote
 * parent (or as a new root when the payload carries none, or as a local child
 * when the job runs inside the dispatching process, see parent()), with
 * `fledge.queue.wait_ms` on the first attempt; JobProcessed, JobFailed and
 * JobExceptionOccurred end it. Whichever arrives first ends the span and the
 * others are no-ops, so it is ended exactly once per attempt.
 */
final class QueueInstrumentation implements Instrumentation
{
    /** Open producer spans per instance; beyond this a dispatch gets no producer span. */
    private const int MAX_OPEN_PRODUCERS = 256;

    /** A producer span still open after this long is ended as a leftover regardless of its fiber. */
    private const int LEFTOVER_GRACE_NANOS = 60_000_000_000;

    /** The tracer the payload hook injects from; older hooks (tests, reloads) go quiet. */
    private static ?Tracer $injecting = null;

    /** @var WeakMap<Job, ActiveSpan> */
    private WeakMap $open;

    /**
     * Producer spans awaiting JobQueued, keyed by the payload `uuid`.
     *
     * @var array<string, array{span: ActiveSpan, started: int, fiber: WeakReference<Fiber<mixed, mixed, mixed, mixed>>|null}>
     */
    private array $producers = [];

    /** @var WeakMap<Job, string> a job running inside its own dispatch (sync) => its producer's uuid */
    private WeakMap $producedBy;

    private ?Tracer $tracer = null;

    private bool $producerSpans = true;

    public function __construct()
    {
        $this->open = new WeakMap;
        $this->producedBy = new WeakMap;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->tracer = $tracer;
        $this->producerSpans = (bool) $app['config']->get('apm.options.queue.producer_spans', true);

        self::$injecting = $tracer;

        Queue::createPayloadUsing(function (mixed $connection = null, mixed $queue = null, mixed $payload = []) use ($tracer): array {
            if (self::$injecting !== $tracer) {
                return [];
            }

            return $this->payloadFields($tracer, $connection, $queue, is_array($payload) ? $payload : []);
        });

        $events = $app->make('events');
        $events->listen(JobQueued::class, $this->queued(...));
        $events->listen(JobProcessing::class, $this->start(...));
        $events->listen(JobProcessed::class, $this->processed(...));
        $events->listen(JobFailed::class, $this->failed(...));
        $events->listen(JobExceptionOccurred::class, $this->failed(...));

        // Must return void: a Looping listener returning false pauses the worker.
        $events->listen(Looping::class, function (): void {
            $this->endLeftovers();
        });

        $app->terminating(function (): void {
            $this->endLeftovers();
        });
    }

    /**
     * The keys the payload hook adds: `apmQueuedAt` always, the trace context
     * of the producer span (or of the current span without one) when valid.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadFields(Tracer $tracer, mixed $connection, mixed $queue, array $payload): array
    {
        $fields = ['apmQueuedAt' => microtime(true)];
        $context = $tracer->storage()->current();

        $producer = $this->startProducer($tracer, $connection, $queue, $payload);

        if ($producer !== null) {
            $context = $producer->span->storeInContext($context);
        }

        TraceContextPropagator::getInstance()->inject($fields, null, $context);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function startProducer(Tracer $tracer, mixed $connection, mixed $queue, array $payload): ?ActiveSpan
    {
        if (! $this->producerSpans || count($this->producers) >= self::MAX_OPEN_PRODUCERS) {
            return null;
        }

        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '' || isset($this->producers[$uuid])) {
            return null;
        }

        // Only inside an existing trace: a dispatch never starts a root trace.
        if (! $tracer->currentSpan()->getContext()->isValid()) {
            return null;
        }

        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        // The sync queue passes no queue; its jobs report the connection as queue.
        $destination = is_string($queue) && $queue !== '' ? $queue : ($connection ?? 'default');
        $jobName = $payload['displayName'] ?? $payload['job'] ?? null;

        $attributes = array_filter([
            'messaging.system' => 'laravel-queue',
            'messaging.operation.type' => 'send',
            'messaging.destination.name' => $destination,
            'laravel.queue.connection' => $connection,
            'laravel.job.name' => is_string($jobName) ? $jobName : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $span = $tracer->startSpan("send {$destination}", SpanKind::KIND_PRODUCER, $attributes, activate: false);

        if (! $span->span->getContext()->isValid()) {
            return null;
        }

        $fiber = Fiber::getCurrent();

        $this->producers[$uuid] = [
            'span' => $span,
            'started' => hrtime(true),
            'fiber' => $fiber === null ? null : WeakReference::create($fiber),
        ];

        return $span;
    }

    private function queued(JobQueued $event): void
    {
        if ($this->producers === []) {
            return;
        }

        try {
            /** @var mixed $payload */
            $payload = $event->payload();
        } catch (Throwable) {
            return;
        }

        $uuid = is_array($payload) ? ($payload['uuid'] ?? null) : null;

        if (! is_string($uuid) || ! isset($this->producers[$uuid])) {
            return;
        }

        $span = $this->producers[$uuid]['span'];
        unset($this->producers[$uuid]);

        $id = $event->id;

        if (is_int($id) || (is_string($id) && $id !== '')) {
            $span->setAttribute('messaging.message.id', $id);
        }

        $span->end();
    }

    private function endProducer(string $uuid): void
    {
        $entry = $this->producers[$uuid] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->producers[$uuid]);

        $entry['span']->end();
    }

    /**
     * End, marked `fledge.queue.unconfirmed`, producer spans no JobQueued
     * will end: those of the fiber that is wrapping up (or of a fiber that has
     * terminated), and any older than the grace period. Not an error: database
     * bulk pushes fire no JobQueued, and a push that really fails throws to its
     * caller. Producer spans of other fibers still running (concurrent requests
     * or job slots) are left alone.
     */
    private function endLeftovers(): void
    {
        if ($this->producers === []) {
            return;
        }

        $current = Fiber::getCurrent();
        $now = hrtime(true);

        foreach ($this->producers as $uuid => $entry) {
            if ($now - $entry['started'] < self::LEFTOVER_GRACE_NANOS && ! $this->fiberDone($entry['fiber'], $current)) {
                continue;
            }

            unset($this->producers[$uuid]);

            $entry['span']->setAttribute('fledge.queue.unconfirmed', true);
            $entry['span']->end();
        }
    }

    /**
     * @param  WeakReference<Fiber<mixed, mixed, mixed, mixed>>|null  $owner
     * @param  Fiber<mixed, mixed, mixed, mixed>|null  $current
     */
    private function fiberDone(?WeakReference $owner, ?Fiber $current): bool
    {
        if ($owner === null) {
            return $current === null;
        }

        $fiber = $owner->get();

        return $fiber === null || $fiber === $current || $fiber->isTerminated();
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

        // The contract says string, but RedisJob returns null for a payload without an id.
        /** @var string|int|null $jobId */
        $jobId = $job->getJobId();
        $attempts = $job->attempts();

        $attributes = array_filter([
            'messaging.system' => 'laravel-queue',
            'messaging.operation.type' => 'process',
            'messaging.destination.name' => $job->getQueue(),
            'messaging.message.id' => $jobId,
            'laravel.queue.connection' => $event->connectionName,
            'laravel.job.name' => $job->resolveName(),
            'laravel.job.attempts' => $attempts,
            'fledge.queue.wait_ms' => $this->waitMs($payload, $attempts),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $this->open[$job] = $this->tracer->startSpan(
            $name,
            SpanKind::KIND_CONSUMER,
            $attributes,
            parent: $this->parent($job, $payload),
        );
    }

    /**
     * Milliseconds between the dispatch (`apmQueuedAt`) and now, on the first
     * attempt only. A `later()` delay is included. Null for payloads from
     * before this key existed and for retries.
     *
     * @param  array<string, mixed>  $payload
     */
    private function waitMs(array $payload, mixed $attempts): ?float
    {
        $queuedAt = $payload['apmQueuedAt'] ?? null;

        if ((! is_int($queuedAt) && ! is_float($queuedAt)) || ! is_numeric($attempts) || (int) $attempts !== 1) {
            return null;
        }

        // Clamped: the dispatching host's clock may run ahead of this one.
        return round(max(0.0, (microtime(true) - $queuedAt) * 1000), 3);
    }

    /**
     * The parent of a job transaction:
     * - the producer span of its dispatch, when that span is still open in
     *   this process (sync queue: the job runs inside the dispatch): a local
     *   child; the producer then ends with the job
     * - the current span, when the job runs in the process that dispatched it
     *   (sync queue, inside the dispatching trace, without a producer span): a
     *   local child, so the sampler and the error-keeping processor treat it
     *   as part of that trace instead of as a separate local root under a
     *   remote parent
     * - the remote parent from the payload otherwise
     * - a new root (false) when the payload carries no trace context
     *
     * @param  array<string, mixed>  $payload
     */
    private function parent(Job $job, array $payload): ContextInterface|false|null
    {
        $remote = $this->remoteParent($payload);

        if ($remote === null) {
            return false;
        }

        $uuid = $payload['uuid'] ?? null;
        $producer = is_string($uuid) ? ($this->producers[$uuid]['span'] ?? null) : null;

        if ($producer !== null && $producer->span->getContext()->getSpanId() === Span::fromContext($remote)->getContext()->getSpanId()) {
            $this->producedBy[$job] = $uuid;

            return $producer->span->storeInContext($this->tracer->storage()->current());
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

        $producer = $this->producedBy[$job] ?? null;
        unset($this->producedBy[$job]);

        try {
            if ($exception !== null) {
                // The worker reports it afterwards; the event is added only once.
                ExceptionRecorder::record($active->span, $exception);
            }
        } finally {
            $active->end();

            // A job that ran inside its own dispatch: the push ends with it.
            if ($producer !== null) {
                $this->endProducer($producer);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Job $job): array
    {
        try {
            // The contract says array, but payload() is an unchecked json_decode() of the raw body.
            /** @var mixed $payload */
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
            // The contract says string, but it is the payload's unchecked `job` key.
            /** @var mixed $name */
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
