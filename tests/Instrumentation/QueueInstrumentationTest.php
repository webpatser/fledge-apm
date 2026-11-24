<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Instrumentation\QueueInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

final class ApmQueueOkJob
{
    public function handle(): void {}
}

final class ApmQueueReleasingJob
{
    use InteractsWithQueue;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release();
        }
    }
}

/**
 * The `database` queue connection on the test sqlite database, with its jobs table.
 */
function queueDatabase(): void
{
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);

    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function runDatabaseJob(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 3));
}

/**
 * @return list<array<string, mixed>>
 */
function databasePayloads(): array
{
    return DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(fn (string $payload): array => json_decode($payload, true))
        ->all();
}

/**
 * @return list<SpanDataInterface>
 */
function queueSpansOfKind(array $spans, int $kind): array
{
    return array_values(array_filter($spans, fn (SpanDataInterface $span): bool => $span->getKind() === $kind));
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

it('wraps a sync job in the PRODUCER span of its dispatch', function () {
    [$tracer, $exporter, $storage] = queueApm();

    $tracer->span('dispatcher', fn () => app('queue')->connection('sync')->push(new ApmQueueOkJob), SpanKind::KIND_SERVER);

    $spans = queueExported($tracer, $exporter);
    $dispatcher = queueSpansNamed($spans, 'dispatcher')[0];
    $producer = queueSpansNamed($spans, 'send sync')[0];
    $job = queueSpansNamed($spans, ApmQueueOkJob::class)[0];
    $attributes = $producer->getAttributes();

    expect($spans)->toHaveCount(3)
        ->and($producer->getKind())->toBe(SpanKind::KIND_PRODUCER)
        ->and($producer->getParentSpanId())->toBe($dispatcher->getSpanId())
        ->and($producer->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($attributes->get('messaging.system'))->toBe('laravel-queue')
        ->and($attributes->get('messaging.operation.type'))->toBe('send')
        ->and($attributes->get('messaging.destination.name'))->toBe('sync')
        ->and($attributes->get('laravel.queue.connection'))->toBe('sync')
        ->and($attributes->get('laravel.job.name'))->toBe(ApmQueueOkJob::class)
        ->and($job->getTraceId())->toBe($dispatcher->getTraceId())
        ->and($job->getParentSpanId())->toBe($producer->getSpanId())
        // in process: a local child, not a separate local root
        ->and($job->getParentContext()->isRemote())->toBeFalse()
        ->and($job->getAttributes()->get('fledge.queue.wait_ms'))->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        // the push ends with the job it ran
        ->and($producer->getEndEpochNanos())->toBeGreaterThanOrEqual($job->getEndEpochNanos())
        ->and($storage->depth())->toBe(0);
});

it('parents the database consumer on the PRODUCER span with a wait time on the first attempt only', function () {
    queueDatabase();
    [$tracer, $exporter] = queueApm();

    $tracer->span('dispatcher', fn () => app('queue')->connection('database')->push(new ApmQueueReleasingJob), SpanKind::KIND_SERVER);

    $payload = databasePayloads()[0];

    runDatabaseJob();
    runDatabaseJob();

    $spans = queueExported($tracer, $exporter);
    $dispatcher = queueSpansNamed($spans, 'dispatcher')[0];
    $producers = queueSpansOfKind($spans, SpanKind::KIND_PRODUCER);
    $attempts = [];

    foreach (queueSpansNamed($spans, ApmQueueReleasingJob::class) as $consumer) {
        $attempts[$consumer->getAttributes()->get('laravel.job.attempts')] = $consumer;
    }

    expect($producers)->toHaveCount(1)
        ->and($producers[0]->getName())->toBe('send default')
        ->and($producers[0]->getParentSpanId())->toBe($dispatcher->getSpanId())
        ->and($producers[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($producers[0]->getAttributes()->get('laravel.queue.connection'))->toBe('database')
        ->and($producers[0]->getAttributes()->get('messaging.destination.name'))->toBe('default')
        ->and($producers[0]->getAttributes()->get('messaging.message.id'))->not->toBeNull()
        ->and($payload['traceparent'])->toContain($producers[0]->getSpanId())
        ->and($payload['apmQueuedAt'])->toBeFloat()
        ->and(array_keys($attempts))->toEqualCanonicalizing([1, 2]);

    foreach ($attempts as $consumer) {
        expect($consumer->getTraceId())->toBe($dispatcher->getTraceId())
            ->and($consumer->getParentSpanId())->toBe($producers[0]->getSpanId())
            ->and($consumer->getParentContext()->isRemote())->toBeTrue();
    }

    expect($attempts[1]->getAttributes()->get('fledge.queue.wait_ms'))->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        ->and($attempts[2]->getAttributes()->has('fledge.queue.wait_ms'))->toBeFalse();
});

it('injects the PRODUCER span through the redis payload path', function () {
    [$tracer, $exporter] = queueApm();

    // Covered without a Redis server: the same createPayloadUsing path the
    // RedisQueue push runs, its JobQueued event, and a real RedisJob.
    $queue = new RedisQueue(app('redis'), 'default');
    $queue->setContainer(app());
    $queue->setConnectionName('redis');

    $dispatcher = $tracer->startSpan('dispatcher', SpanKind::KIND_SERVER);
    $raw = (fn (): string => $this->createPayload(new ApmQueueOkJob, 'default'))->call($queue);
    $payload = json_decode($raw, true);
    event(new JobQueued('redis', 'default', $payload['id'], new ApmQueueOkJob, $raw, null));
    $dispatcher->end();

    $job = new RedisJob(app(), $queue, $raw, $raw, 'redis', 'default');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    $spans = queueExported($tracer, $exporter);
    $producer = queueSpansNamed($spans, 'send default')[0];
    $consumer = queueSpansNamed($spans, ApmQueueOkJob::class)[0];

    expect($producer->getKind())->toBe(SpanKind::KIND_PRODUCER)
        ->and($producer->getParentSpanId())->toBe($dispatcher->span->getContext()->getSpanId())
        ->and($producer->getAttributes()->get('laravel.queue.connection'))->toBe('redis')
        ->and($producer->getAttributes()->get('messaging.message.id'))->toBe($payload['id'])
        ->and($payload['traceparent'])->toContain($producer->getSpanId())
        ->and($payload['apmQueuedAt'])->toBeFloat()
        ->and($consumer->getParentSpanId())->toBe($producer->getSpanId())
        ->and($consumer->getParentContext()->isRemote())->toBeTrue()
        ->and($consumer->getAttributes()->get('fledge.queue.wait_ms'))->toBeFloat();
});

it('injects the current span and starts no PRODUCER span with producer spans off', function () {
    queueDatabase();
    config()->set('apm.options.queue.producer_spans', false);
    [$tracer, $exporter] = queueApm();

    $tracer->span('dispatcher', function (): void {
        app('queue')->connection('sync')->push(new ApmQueueOkJob);
        app('queue')->connection('database')->push(new ApmQueueOkJob);
    }, SpanKind::KIND_SERVER);

    $payload = databasePayloads()[0];
    $spans = queueExported($tracer, $exporter);
    $dispatcher = queueSpansNamed($spans, 'dispatcher')[0];
    $job = queueSpansNamed($spans, ApmQueueOkJob::class)[0];

    expect($spans)->toHaveCount(2)
        ->and(queueSpansOfKind($spans, SpanKind::KIND_PRODUCER))->toBeEmpty()
        ->and($job->getParentSpanId())->toBe($dispatcher->getSpanId())
        ->and($job->getParentContext()->isRemote())->toBeFalse()
        ->and($payload['traceparent'])->toContain($dispatcher->getSpanId())
        // added regardless of the toggle, for the wait time
        ->and($payload['apmQueuedAt'])->toBeFloat();
});

it('starts no PRODUCER span for a dispatch outside any trace', function () {
    queueDatabase();
    [$tracer, $exporter] = queueApm();

    app('queue')->connection('database')->push(new ApmQueueOkJob);

    $payload = databasePayloads()[0];

    expect(queueExported($tracer, $exporter))->toBeEmpty()
        ->and($payload)->not->toHaveKey('traceparent')
        ->and($payload['apmQueuedAt'])->toBeFloat();

    runDatabaseJob();

    $spans = queueExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getParentContext()->isValid())->toBeFalse()
        ->and($spans[0]->getAttributes()->get('fledge.queue.wait_ms'))->toBeFloat();
});

it('processes payloads from before apmQueuedAt without a wait time', function () {
    [$tracer, $exporter] = queueApm();

    $traced = syncJobWith(['traceparent' => '00-'.str_repeat('1f', 16).'-'.str_repeat('2e', 8).'-01']);
    $plain = syncJobWith(['uuid' => 'def']);

    foreach ([$traced, $plain] as $job) {
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));
    }

    $spans = queueExported($tracer, $exporter);

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->getAttributes()->has('fledge.queue.wait_ms'))->toBeFalse()
        ->and($spans[1]->getAttributes()->has('fledge.queue.wait_ms'))->toBeFalse()
        ->and($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($spans[1]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET);
});

it('ends PRODUCER spans that never see JobQueued as unconfirmed, without error status', function (string $wrapUp) {
    queueDatabase();
    // A push that fails after its payload was built: the insert throws, no JobQueued.
    config()->set('queue.connections.database.table', 'missing_jobs');
    [$tracer, $exporter] = queueApm();

    $dispatcher = $tracer->startSpan('dispatcher', SpanKind::KIND_SERVER);

    $thrown = null;

    try {
        app('queue')->connection('database')->push(new ApmQueueOkJob);
    } catch (QueryException $exception) {
        $thrown = $exception;
    }

    $dispatcher->end();

    expect($thrown)->toBeInstanceOf(QueryException::class);

    expect(queueSpansOfKind(queueExported($tracer, $exporter), SpanKind::KIND_PRODUCER))->toBeEmpty();

    match ($wrapUp) {
        'looping' => event(new Looping('database', 'default')),
        'terminating' => app()->terminate(),
    };

    $producers = queueSpansOfKind(queueExported($tracer, $exporter), SpanKind::KIND_PRODUCER);

    // The failure belongs to whoever caught the exception, not to the producer span.
    expect($producers)->toHaveCount(1)
        ->and($producers[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($producers[0]->getAttributes()->get('fledge.queue.unconfirmed'))->toBeTrue()
        ->and($producers[0]->getParentSpanId())->toBe($dispatcher->span->getContext()->getSpanId());
})->with(['looping', 'terminating']);

it('ends the PRODUCER spans of a database bulk push as unconfirmed, without error status', function (string $wrapUp) {
    queueDatabase();
    [$tracer, $exporter] = queueApm();

    $dispatcher = $tracer->startSpan('dispatcher', SpanKind::KIND_SERVER);
    // A database bulk insert (and so a bus batch on the database driver) fires no JobQueued.
    app('queue')->connection('database')->bulk([new ApmQueueOkJob, new ApmQueueOkJob]);
    $dispatcher->end();

    expect(queueSpansOfKind(queueExported($tracer, $exporter), SpanKind::KIND_PRODUCER))->toBeEmpty()
        ->and(databasePayloads())->toHaveCount(2);

    match ($wrapUp) {
        'looping' => event(new Looping('database', 'default')),
        'terminating' => app()->terminate(),
    };

    $producers = queueSpansOfKind(queueExported($tracer, $exporter), SpanKind::KIND_PRODUCER);

    expect($producers)->toHaveCount(2)
        ->and(array_map(fn (SpanDataInterface $span): string => $span->getStatus()->getCode(), $producers))->toBe([StatusCode::STATUS_UNSET, StatusCode::STATUS_UNSET])
        ->and(array_map(fn (SpanDataInterface $span): mixed => $span->getAttributes()->get('fledge.queue.unconfirmed'), $producers))->toBe([true, true])
        ->and($producers[0]->getParentSpanId())->toBe($dispatcher->span->getContext()->getSpanId());
})->with(['looping', 'terminating']);

it('caps the open PRODUCER spans and injects the current span beyond the cap', function () {
    queueDatabase();
    [$tracer, $exporter] = queueApm();

    $dispatcher = $tracer->startSpan('dispatcher', SpanKind::KIND_SERVER);
    app('queue')->connection('database')->bulk(array_fill(0, 260, new ApmQueueOkJob));
    $dispatcher->end();

    event(new Looping('database', 'default'));

    $dispatcherId = $dispatcher->span->getContext()->getSpanId();
    $direct = array_filter(databasePayloads(), fn (array $payload): bool => str_contains($payload['traceparent'], $dispatcherId));

    expect(queueSpansOfKind(queueExported($tracer, $exporter), SpanKind::KIND_PRODUCER))->toHaveCount(256)
        ->and($direct)->toHaveCount(4);
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
    $producer = queueSpansNamed($spans, 'send sync')[0];

    expect($spans)->toHaveCount(3)
        ->and($producer->getParentSpanId())->toBe(queueSpansNamed($spans, 'dispatcher')[0]->getSpanId())
        ->and($job->getParentSpanId())->toBe($producer->getSpanId())
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
