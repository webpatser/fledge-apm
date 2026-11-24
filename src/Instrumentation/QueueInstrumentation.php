<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fiber;
use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ErrorType;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Metrics\MetricsFactory;
use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
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
 *
 * Metrics (`apm.metrics.groups.queue`), recorded whether or not the consumer
 * span is sampled, with only `messaging.system`, `messaging.destination.name`,
 * `laravel.queue.connection` and `laravel.job.name` (plus `error.type`, the
 * exception class short name, on failures); never the job id:
 * - `messaging.client.consumed.messages` counter: one per attempt started
 * - `messaging.process.duration` histogram (s): one per attempt, at the end
 * - `fledge.queue.job.wait_time` histogram (s): first attempt only, from
 *   `apmQueuedAt`, the rule `fledge.queue.wait_ms` follows; never for the
 *   sync queue, whose jobs run inside the dispatch
 * - `fledge.queue.job.retries` counter: JobReleasedAfterException
 * - `fledge.queue.job.failures` counter: JobFailed
 */
final class QueueInstrumentation implements Instrumentation
{
    /** Boundaries of `messaging.process.duration`, in seconds: 5 ms to 10 minutes. */
    public const array PROCESS_DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600];

    /** Boundaries of `fledge.queue.job.wait_time`, in seconds: 10 ms to 1 hour. */
    public const array WAIT_TIME_BUCKETS = [0.01, 0.05, 0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600];

    private bool $metrics = false;

    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    /** @var WeakMap<Job, array{int|float, array<non-empty-string, string>}> start time and attributes of the attempt being timed */
    private WeakMap $timed;

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
        $this->timed = new WeakMap;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->tracer = $tracer;
        $this->producerSpans = (bool) $app['config']->get('apm.options.queue.producer_spans', true);
        $this->metrics = (bool) $app['config']->get('apm.metrics.groups.queue', true);

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
        $events->listen(JobReleasedAfterException::class, $this->released(...));

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
        $waitMs = $this->waitMs($payload, $attempts);

        $this->startMetrics($job, $event->connectionName, $waitMs);

        $attributes = array_filter([
            'messaging.system' => 'laravel-queue',
            'messaging.operation.type' => 'process',
            'messaging.destination.name' => $job->getQueue(),
            'messaging.message.id' => $jobId,
            'laravel.queue.connection' => $event->connectionName,
            'laravel.job.name' => $job->resolveName(),
            'laravel.job.attempts' => $attempts,
            'fledge.queue.wait_ms' => $waitMs,
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

        if ($event instanceof JobFailed) {
            $this->countJob('fledge.queue.job.failures', 'Jobs that failed for good.', $event->job, $event->connectionName, $event->exception);
        }
    }

    private function released(JobReleasedAfterException $event): void
    {
        $exception = $event->exception instanceof Throwable ? $event->exception : null;

        $this->countJob('fledge.queue.job.retries', 'Jobs released back onto the queue after an exception.', $event->job, $event->connectionName, $exception);
    }

    /**
     * Whether this process records the queue metrics. Instruments are only
     * created while it does, so none is ever bound to a no-op meter.
     */
    private function measuring(): bool
    {
        return $this->metrics && Metrics::enabled();
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function metricAttributes(mixed $job, mixed $connection): array
    {
        $queue = $job instanceof Job ? $job->getQueue() : null;
        $name = $job instanceof Job ? $job->resolveName() : null;

        /** @var array<non-empty-string, string> $attributes */
        $attributes = array_filter([
            'messaging.system' => 'laravel-queue',
            'messaging.destination.name' => is_string($queue) ? $queue : null,
            'laravel.queue.connection' => is_string($connection) ? $connection : null,
            'laravel.job.name' => is_string($name) ? $name : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $attributes;
    }

    /**
     * The attempt starts: count it, record its wait time (first attempt
     * only) and remember when it began and under which attributes.
     */
    private function startMetrics(Job $job, mixed $connection, ?float $waitMs): void
    {
        if (! $this->measuring() || isset($this->timed[$job])) {
            return;
        }

        try {
            $attributes = $this->metricAttributes($job, $connection);
            $guard = Metrics::cardinality();

            $this->timed[$job] = [hrtime(true), $attributes];

            $name = 'messaging.client.consumed.messages';
            $this->counter($name, '{message}', 'Jobs received by a worker, one per attempt.')->add(1, $guard->attributes($name, $attributes));

            // A sync job runs inside its dispatch: its near-zero wait is no sample.
            if ($waitMs !== null && ! $job instanceof SyncJob) {
                $name = 'fledge.queue.job.wait_time';
                $this->histogram($name, 'Time from dispatch to the first attempt, a later() delay included.', self::WAIT_TIME_BUCKETS)
                    ->record($waitMs / 1000, $guard->attributes($name, $attributes));
            }
        } catch (Throwable) {
            // Instrumentation never breaks the worker.
        }
    }

    /**
     * The attempt ends (processed, failed or released after an exception,
     * whichever event comes first).
     */
    private function endMetrics(Job $job, ?Throwable $exception): void
    {
        $timing = $this->timed[$job] ?? null;

        if ($timing === null) {
            return;
        }

        unset($this->timed[$job]);

        try {
            [$started, $attributes] = $timing;

            if ($exception !== null) {
                $attributes['error.type'] = ErrorType::shortName($exception);
            }

            $name = 'messaging.process.duration';
            $this->histogram($name, 'Duration of one attempt to process a job.', self::PROCESS_DURATION_BUCKETS)
                ->record((hrtime(true) - $started) / 1_000_000_000, Metrics::cardinality()->attributes($name, $attributes));
        } catch (Throwable) {
            // Instrumentation never breaks the worker.
        }
    }

    private function countJob(string $name, string $description, mixed $job, mixed $connection, mixed $exception): void
    {
        if (! $this->measuring()) {
            return;
        }

        try {
            $attributes = $this->metricAttributes($job, $connection);

            if ($exception instanceof Throwable) {
                $attributes['error.type'] = ErrorType::shortName($exception);
            }

            $this->counter($name, '{job}', $description)->add(1, Metrics::cardinality()->attributes($name, $attributes));
        } catch (Throwable) {
            // Instrumentation never breaks the worker.
        }
    }

    private function counter(string $name, string $unit, string $description): CounterInterface
    {
        return $this->counters[$name] ??= Metrics::meter(MetricsFactory::INSTRUMENTATION_NAME)->createCounter($name, $unit, $description);
    }

    /**
     * @param  list<int|float>  $boundaries
     */
    private function histogram(string $name, string $description, array $boundaries): HistogramInterface
    {
        return $this->histograms[$name] ??= Metrics::meter(MetricsFactory::INSTRUMENTATION_NAME)
            ->createHistogram($name, 's', $description, ['ExplicitBucketBoundaries' => $boundaries]);
    }

    private function end(Job $job, ?Throwable $exception): void
    {
        $this->endMetrics($job, $exception);

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
