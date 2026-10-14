<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Instrumentation\QueueInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

final class ApmQueueOkJob
{
    public function handle(): void {}
}

final class ApmQueueFailingJob
{
    public function handle(): void
    {
        throw new RuntimeException('job exploded');
    }
}

/**
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function queueApm(): array
{
    [$tracer, $exporter, $storage] = memoryTracer();

    (new QueueInstrumentation)->register(app(), $tracer);

    return [$tracer, $exporter, $storage];
}

/**
 * @return list<SpanDataInterface>
 */
function queueExported(Tracer $tracer, InMemoryExporter $exporter): array
{
    expect($tracer->flush())->toBeTrue();

    return $exporter->getSpans();
}

/**
 * @return list<SpanDataInterface>
 */
function queueSpansNamed(array $spans, string $name): array
{
    return array_values(array_filter($spans, fn (SpanDataInterface $span): bool => $span->getName() === $name));
}

function syncJobWith(array $extra = []): SyncJob
{
    $payload = json_encode($extra + [
        'uuid' => 'abc',
        'displayName' => 'RemoteJob',
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => [],
        'attempts' => 0,
    ]);

    return new SyncJob(app(), $payload, 'sync', 'default');
}

it('records a CONSUMER transaction per processed job with queue attributes', function () {
    [$tracer, $exporter, $storage] = queueApm();

    app('queue')->connection('sync')->push(new ApmQueueOkJob);

    $spans = queueExported($tracer, $exporter);
    $jobs = queueSpansNamed($spans, ApmQueueOkJob::class);

    expect($jobs)->toHaveCount(1);

    $job = $jobs[0];

    expect($spans)->toHaveCount(1)
        ->and($job->getKind())->toBe(SpanKind::KIND_CONSUMER)
        ->and($job->getParentContext()->isValid())->toBeFalse()
        ->and($job->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($job->getAttributes()->get('laravel.queue.connection'))->toBe('sync')
        ->and($job->getAttributes()->get('messaging.destination.name'))->toBe('sync') // SyncJob reports its connection as queue
        ->and($job->getAttributes()->get('laravel.job.attempts'))->toBe(1)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse()
        ->and($storage->depth())->toBe(0);
});

it('propagates the dispatching trace through the job payload', function () {
    [$tracer, $exporter] = queueApm();

    $tracer->span('dispatcher', fn () => app('queue')->connection('sync')->push(new ApmQueueOkJob), SpanKind::KIND_SERVER);

    $spans = queueExported($tracer, $exporter);
    $dispatcher = queueSpansNamed($spans, 'dispatcher')[0];
    $job = queueSpansNamed($spans, ApmQueueOkJob::class)[0];

    expect($job->getTraceId())->toBe($dispatcher->getTraceId())
        ->and($job->getParentSpanId())->toBe($dispatcher->getSpanId())
        // in process: a local child, not a separate local root
        ->and($job->getParentContext()->isRemote())->toBeFalse();
});

it('keeps a failed sync job inside the trace of an unsampled dispatcher', function () {
    [$tracer, $exporter] = memoryTracer(['sample_rate' => 0.0]);
    (new QueueInstrumentation)->register(app(), $tracer);

    $dispatcher = $tracer->startSpan('dispatcher', SpanKind::KIND_SERVER);

    expect(fn () => app('queue')->connection('sync')->push(new ApmQueueFailingJob))
        ->toThrow(RuntimeException::class);

    // The job is not a local root: nothing is decided before the dispatcher ends.
    expect(queueExported($tracer, $exporter))->toBeEmpty();

    $dispatcher->end();

    $spans = queueExported($tracer, $exporter);
    $job = queueSpansNamed($spans, ApmQueueFailingJob::class)[0];

    expect($spans)->toHaveCount(2)
        ->and($job->getParentSpanId())->toBe(queueSpansNamed($spans, 'dispatcher')[0]->getSpanId())
        ->and($job->getParentContext()->isRemote())->toBeFalse();
});

it('treats a payload from another trace as a remote parent even inside a span', function () {
    [$tracer, $exporter] = queueApm();

    $traceId = str_repeat('1f', 16);
    $job = syncJobWith(['traceparent' => "00-{$traceId}-".str_repeat('2e', 8).'-01']);

    $tracer->span('unrelated', function () use ($job): void {
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));
    });

    $span = queueSpansNamed(queueExported($tracer, $exporter), 'RemoteJob')[0];

    expect($span->getTraceId())->toBe($traceId)
        ->and($span->getParentContext()->isRemote())->toBeTrue();
});

it('continues the remote parent found in the payload', function () {
    [$tracer, $exporter, $storage] = queueApm();

    $traceId = str_repeat('1f', 16);
    $parentId = str_repeat('2e', 8);

    $job = syncJobWith(['traceparent' => "00-{$traceId}-{$parentId}-01"]);

    event(new JobProcessing('sync', $job));

    expect($tracer->currentSpan()->getContext()->getTraceId())->toBe($traceId);

    event(new JobProcessed('sync', $job));

    $spans = queueExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('RemoteJob')
        ->and($spans[0]->getTraceId())->toBe($traceId)
        ->and($spans[0]->getParentSpanId())->toBe($parentId)
        ->and($spans[0]->getParentContext()->isRemote())->toBeTrue()
        ->and($storage->depth())->toBe(0);
});

it('does not leak one job trace into the next job on the same worker', function () {
    [$tracer, $exporter] = queueApm();

    $first = syncJobWith(['traceparent' => '00-'.str_repeat('1f', 16).'-'.str_repeat('2e', 8).'-01']);
    $second = syncJobWith(['uuid' => 'def']);

    event(new JobProcessing('sync', $first));
    event(new JobProcessed('sync', $first));
    event(new JobProcessing('sync', $second));
    event(new JobProcessed('sync', $second));

    $spans = queueExported($tracer, $exporter);

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->getTraceId())->not->toBe($spans[1]->getTraceId())
        ->and($spans[1]->getParentContext()->isValid())->toBeFalse();
});

it('marks a failed job as errored and ends the span once despite two failure events', function () {
    [$tracer, $exporter, $storage] = queueApm();

    expect(fn () => app('queue')->connection('sync')->push(new ApmQueueFailingJob))
        ->toThrow(RuntimeException::class, 'job exploded');

    $spans = queueExported($tracer, $exporter);
    $jobs = queueSpansNamed($spans, ApmQueueFailingJob::class);

    expect($jobs)->toHaveCount(1)
        ->and($spans)->toHaveCount(1)
        ->and($jobs[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and(array_map(fn ($event) => $event->getName(), $jobs[0]->getEvents()))->toContain('exception')
        ->and($storage->depth())->toBe(0);
});

it('ends the span of an attempt that raised an exception and will be retried', function () {
    [$tracer, $exporter, $storage] = queueApm();

    $job = syncJobWith();

    event(new JobProcessing('sync', $job));
    event(new JobExceptionOccurred('sync', $job, new LogicException('try again')));
    event(new JobFailed('sync', $job, new LogicException('try again')));
    event(new JobProcessed('sync', $job));

    $spans = queueExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($storage->depth())->toBe(0);
});

it('ignores end events for a job that never started', function () {
    [$tracer, $exporter] = queueApm();

    event(new JobProcessed('sync', syncJobWith()));

    expect(queueExported($tracer, $exporter))->toBeEmpty();
});
