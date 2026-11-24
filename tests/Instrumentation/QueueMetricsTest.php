<?php

declare(strict_types=1);

use Fledge\Apm\Instrumentation\QueueInstrumentation;
use Fledge\Apm\Metrics\CardinalityGuard;
use Fledge\Apm\Metrics\Metrics;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Jobs\SyncJob;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

/** A job pulled off a queue (not sync) that reports a chosen attempt number, as a retried database or redis job does. */
final class ApmMetricsAttemptJob extends Job implements JobContract
{
    public int $attemptNumber = 1;

    public function __construct(Container $container, private readonly string $payload, string $connectionName, string $queue)
    {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return 'job-id-1';
    }

    public function getRawBody(): string
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return $this->attemptNumber;
    }
}

const QUEUE_METRICS_ALLOWED = ['messaging.system', 'messaging.destination.name', 'laravel.queue.connection', 'laravel.job.name', 'error.type'];

/**
 * @param  array<string, mixed>  $metricsConfig
 * @return array{Metrics, InMemoryExporter}
 */
function queueMetricsRuntime(array $metricsConfig = [], string $sapi = 'cli'): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true], $metricsConfig));

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    $metrics = new Metrics(config('apm'), $exporter, sapi: $sapi);
    app()->instance(Metrics::class, $metrics);

    [$tracer] = memoryTracer();
    (new QueueInstrumentation)->register(app(), $tracer);

    return [$metrics, $exporter];
}

function queueMetricsJob(int $attempt = 1, string $name = 'RemoteJob', ?float $queuedAt = null): ApmMetricsAttemptJob
{
    $job = new ApmMetricsAttemptJob(app(), json_encode(array_filter([
        'uuid' => 'job-uuid-1234',
        'displayName' => $name,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => [],
        'apmQueuedAt' => $queuedAt ?? microtime(true) - 2.0,
    ], static fn (mixed $value): bool => $value !== null)), 'sync', 'emails');

    $job->attemptNumber = $attempt;

    return $job;
}

/**
 * @return array<string, Metric>
 */
function queueMetricsExported(Metrics $metrics, InMemoryExporter $exporter): array
{
    $metrics->detect();
    $metrics->collect();

    $byName = [];

    foreach ($exporter->collect() as $metric) {
        $byName[$metric->name] = $metric;
    }

    $metrics->stop();

    return $byName;
}

/**
 * @return list<array<string, mixed>>
 */
function queueMetricsSets(Metric $metric): array
{
    $sets = [];

    foreach ($metric->data->dataPoints as $point) {
        $sets[] = $point->attributes->toArray();
    }

    return $sets;
}

function queueMetricsSum(Metric $metric): int|float
{
    $sum = 0;

    foreach ($metric->data->dataPoints as $point) {
        $sum += $point->value;
    }

    return $sum;
}

it('records process duration, consumed messages and wait time of a first attempt', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $job = queueMetricsJob();

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));

    $exported = queueMetricsExported($metrics, $exporter);

    expect(array_keys($exported))->toEqualCanonicalizing([
        'messaging.process.duration',
        'messaging.client.consumed.messages',
        'fledge.queue.job.wait_time',
    ]);

    $duration = $exported['messaging.process.duration'];
    $wait = $exported['fledge.queue.job.wait_time'];

    expect($duration->unit)->toBe('s')
        ->and($duration->data->dataPoints[0]->explicitBounds)->toEqual([0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600])
        ->and($duration->data->dataPoints[0]->count)->toBe(1)
        ->and($wait->unit)->toBe('s')
        ->and($wait->data->dataPoints[0]->explicitBounds)->toEqual([0.01, 0.05, 0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600])
        ->and($wait->data->dataPoints[0]->count)->toBe(1)
        ->and($wait->data->dataPoints[0]->sum)->toBeGreaterThanOrEqual(2.0)->toBeLessThan(10.0)
        ->and($exported['messaging.client.consumed.messages']->unit)->toBe('{message}')
        ->and(queueMetricsSum($exported['messaging.client.consumed.messages']))->toBe(1);
});

it('records only the allowed attributes and never the job id', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $job = queueMetricsJob();

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));

    foreach (queueMetricsExported($metrics, $exporter) as $metric) {
        $sets = queueMetricsSets($metric);

        expect($sets)->toHaveCount(1)
            ->and($sets[0])->toEqual([
                'messaging.system' => 'laravel-queue',
                'messaging.destination.name' => $job->getQueue(),
                'laravel.queue.connection' => 'sync',
                'laravel.job.name' => 'RemoteJob',
            ])
            ->and(array_diff(array_keys($sets[0]), QUEUE_METRICS_ALLOWED))->toBe([])
            ->and(json_encode($sets))->not->toContain('job-uuid-1234');
    }
});

it('records the wait time on the first attempt only', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $retry = queueMetricsJob(attempt: 2);

    event(new JobProcessing('sync', $retry));
    event(new JobProcessed('sync', $retry));

    $exported = queueMetricsExported($metrics, $exporter);

    expect($exported)->not->toHaveKey('fledge.queue.job.wait_time')
        ->and($exported)->toHaveKey('messaging.process.duration')
        ->and(queueMetricsSum($exported['messaging.client.consumed.messages']))->toBe(1);
});

it('records no wait time for a payload from before apmQueuedAt existed', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $job = new ApmMetricsAttemptJob(app(), json_encode(['uuid' => 'old', 'displayName' => 'OldJob', 'job' => 'x@call', 'data' => []]), 'sync', 'default');

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));

    expect(queueMetricsExported($metrics, $exporter))->not->toHaveKey('fledge.queue.job.wait_time');
});

it('records no wait time for a sync job, which runs inside its dispatch', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $job = new SyncJob(app(), json_encode([
        'uuid' => 'sync-uuid',
        'displayName' => 'SyncedJob',
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => [],
        'apmQueuedAt' => microtime(true) - 2.0,
    ]), 'sync', 'default');

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));

    $exported = queueMetricsExported($metrics, $exporter);

    expect($exported)->not->toHaveKey('fledge.queue.job.wait_time')
        ->and($exported)->toHaveKey('messaging.process.duration')
        ->and(queueMetricsSum($exported['messaging.client.consumed.messages']))->toBe(1);
});

it('counts retries and failures and tags a failed attempt with the exception class', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $error = new RuntimeException('job exploded for secret@example.test');

    $first = queueMetricsJob();
    event(new JobProcessing('sync', $first));
    event(new JobExceptionOccurred('sync', $first, $error));
    event(new JobReleasedAfterException('sync', $first, 0, $error));

    $last = queueMetricsJob(attempt: 2);
    event(new JobProcessing('sync', $last));
    event(new JobExceptionOccurred('sync', $last, $error));
    event(new JobFailed('sync', $last, $error));

    $exported = queueMetricsExported($metrics, $exporter);

    $retries = $exported['fledge.queue.job.retries'];
    $failures = $exported['fledge.queue.job.failures'];
    $duration = $exported['messaging.process.duration'];

    expect($retries->unit)->toBe('{job}')
        ->and(queueMetricsSum($retries))->toBe(1)
        ->and($failures->unit)->toBe('{job}')
        ->and(queueMetricsSum($failures))->toBe(1)
        ->and(queueMetricsSets($failures)[0]['error.type'])->toBe('RuntimeException')
        ->and($duration->data->dataPoints[0]->count)->toBe(2)
        ->and(queueMetricsSets($duration)[0]['error.type'])->toBe('RuntimeException');

    foreach ([$retries, $failures, $duration] as $metric) {
        expect(array_diff(array_keys(queueMetricsSets($metric)[0]), QUEUE_METRICS_ALLOWED))->toBe([])
            ->and(json_encode(queueMetricsSets($metric)))->not->toContain('secret@example.test');
    }
});

it('records the overflow set once the cardinality limit is exceeded', function () {
    [$metrics, $exporter] = queueMetricsRuntime(['cardinality_limit' => 2]);

    foreach (['JobA', 'JobB', 'JobC', 'JobD'] as $name) {
        $job = queueMetricsJob(name: $name);

        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));
    }

    $sets = queueMetricsSets(queueMetricsExported($metrics, $exporter)['messaging.client.consumed.messages']);
    $overflow = array_values(array_filter($sets, fn (array $set): bool => isset($set[CardinalityGuard::OVERFLOW_ATTRIBUTE])));

    expect($sets)->toHaveCount(3)
        ->and($overflow)->toHaveCount(1)
        ->and($overflow[0])->toBe([CardinalityGuard::OVERFLOW_ATTRIBUTE => true]);
});

it('records nothing with metrics.groups.queue off', function () {
    [$metrics, $exporter] = queueMetricsRuntime(['groups' => ['queue' => false]]);
    $job = queueMetricsJob();
    $error = new RuntimeException('boom');

    event(new JobProcessing('sync', $job));
    event(new JobExceptionOccurred('sync', $job, $error));
    event(new JobReleasedAfterException('sync', $job, 0, $error));
    event(new JobFailed('sync', $job, $error));

    expect(queueMetricsExported($metrics, $exporter))->toBe([]);
});

it('exports nothing from a short-lived CLI process', function () {
    [$metrics, $exporter] = queueMetricsRuntime();
    $job = queueMetricsJob();

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));
    app()->terminate();

    expect($metrics->longRunning())->toBeFalse()
        ->and($metrics->collect())->toBeFalse()
        ->and($exporter->collect())->toBe([]);
});

it('exports nothing under PHP-FPM with metrics.fpm off', function () {
    [$metrics, $exporter] = queueMetricsRuntime(['fpm' => false], 'fpm-fcgi');
    $job = queueMetricsJob();

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));
    app()->terminate();

    expect(Metrics::enabled())->toBeFalse()
        ->and($exporter->collect())->toBe([]);
});
