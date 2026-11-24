<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\ThrottledLogWriter;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Metrics\QueueBacklogObserver;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\SessionStore;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStoreBackend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

use function Fledge\Async\delay;

final class ApmBacklogJob
{
    public function handle(): void {}
}

const BACKLOG_GAUGES = ['fledge.queue.pending', 'fledge.queue.delayed', 'fledge.queue.reserved', 'fledge.queue.oldest_age'];

/**
 * The `database` queue connection on the test sqlite database, with its jobs table.
 */
function backlogDatabase(): void
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

/**
 * A long-running Metrics instance exporting into an in-memory exporter.
 *
 * @return array{Metrics, InMemoryExporter}
 */
function backlogMetrics(): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true, 'interval' => 1]));

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    $metrics = new Metrics(config('apm'), $exporter);
    $metrics->detect();

    return [$metrics, $exporter];
}

/**
 * An observer on the database queue with a 1 s interval (lock TTL 3 s) and a
 * clock that follows the test's time travel.
 *
 * @param  array<string, list<string>>  $queues
 * @param  (Closure(): ?RepositoryContract)|null  $store
 */
function backlogObserver(array $queues = ['database' => ['default']], ?Closure $store = null, ?ThrottledLogWriter $log = null, int $interval = 1, string $key = 'fledge-apm:queue-backlog:tests:testing'): QueueBacklogObserver
{
    return $GLOBALS['backlogObservers'][] = new QueueBacklogObserver(
        app('queue'),
        $queues,
        $store ?? static fn (): RepositoryContract => app('cache')->store('array'),
        $key,
        $interval,
        $log,
        clock: static fn (): float => (float) Carbon::now()->format('U.u'),
    );
}

/**
 * @return array{ThrottledLogWriter, TestHandler}
 */
function backlogLog(): array
{
    $handler = new TestHandler;
    $logger = new Logger('backlog-tests', [$handler]);

    return [new ThrottledLogWriter(static fn (): Logger => $logger), $handler];
}

/**
 * Collect once; data points per metric name. A metric without data points is absent.
 *
 * @return array<string, list<array{attributes: array<string, mixed>, value: int|float}>>
 */
function backlogExport(Metrics $metrics, InMemoryExporter $exporter): array
{
    $metrics->collect();

    $points = [];

    foreach ($exporter->collect(true) as $metric) {
        foreach ($metric->data->dataPoints as $point) {
            $points[$metric->name][] = ['attributes' => $point->attributes->toArray(), 'value' => $point->value];
        }
    }

    return $points;
}

/**
 * @param  list<array{attributes: array<string, mixed>, value: int|float}>  $points
 */
function backlogValue(array $points, string $queue): int|float|null
{
    foreach ($points as $point) {
        if ($point['attributes']['messaging.destination.name'] === $queue) {
            return $point['value'];
        }
    }

    return null;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00'));
});

afterEach(function () {
    // Stopped here too, so no timer or queued start of a failed test fires
    // inside a later one.
    foreach ($GLOBALS['backlogObservers'] ?? [] as $observer) {
        $observer->stop();
    }

    $GLOBALS['backlogObservers'] = [];

    if (app()->bound(QueueBacklogObserver::class)) {
        app(QueueBacklogObserver::class)->stop();
    }
});

it('ends the lease before a store that expires on whole seconds can hand the lock to another process', function () {
    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');

    config()->set('cache.stores.database', ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null, 'lock_table' => 'cache_locks']);
    Schema::create('cache_locks', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });

    $store = static fn (): RepositoryContract => app('cache')->store('database');
    $this->travelTo(Carbon::parse('2026-11-24 12:00:00.900'));

    [$metricsA] = backlogMetrics();
    [$metricsB] = backlogMetrics();
    $a = backlogObserver(store: $store, interval: 10);
    $b = backlogObserver(store: $store, interval: 10);
    $a->start($metricsA);
    $b->start($metricsB);

    expect($a->ttl())->toBe(15)
        ->and($a->lease())->toBe(12.5)
        ->and($a->sample())->toBeTrue()
        ->and($b->sample())->toBeFalse();

    // The database lock expires on whole seconds: 12:00:15, 0.9 s before
    // the sample time plus the TTL.
    expect((int) DB::table('cache_locks')->value('expiration'))->toBe(Carbon::parse('2026-11-24 12:00:15')->getTimestamp());

    $this->travelTo(Carbon::parse('2026-11-24 12:00:13.300'));

    expect($a->reporting())->toBeTrue();

    $this->travelTo(Carbon::parse('2026-11-24 12:00:13.500'));

    expect($a->reporting())->toBeFalse();

    // The store lets B take over at 12:00:15; A went quiet before that.
    $this->travelTo(Carbon::parse('2026-11-24 12:00:15'));

    expect($b->sample())->toBeTrue()
        ->and($b->reporting())->toBeTrue()
        ->and($a->reporting())->toBeFalse();

    $a->stop();
    $b->stop();
    $metricsA->stop();
    $metricsB->stop();
});

it('tries a failing connection once per sample, not once per queue', function () {
    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');
    config()->set('queue.connections.missing_table', ['driver' => 'database', 'connection' => null, 'table' => 'no_such_jobs', 'queue' => 'default', 'retry_after' => 90]);

    [$log, $handler] = backlogLog();
    [$metrics, $exporter] = backlogMetrics();
    $observer = backlogObserver(['missing_table' => ['first', 'second', 'third'], 'database' => ['default']], log: $log);
    $observer->start($metrics);

    expect($observer->sample())->toBeTrue();

    $failures = array_values(array_filter($handler->getRecords(), static fn ($record): bool => str_contains($record->message, 'missing_table')));
    $exported = backlogExport($metrics, $exporter);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->message)->toContain('missing_table:first')
        ->and(backlogValue($exported['fledge.queue.pending'], 'default'))->toBe(1)
        ->and($exported['fledge.queue.pending'])->toHaveCount(1);

    $observer->stop();
    $metrics->stop();
});

it('reports pending, delayed, reserved and oldest age per queue with the three allowed attributes', function () {
    backlogDatabase();
    $queue = app('queue')->connection('database');

    foreach (range(1, 3) as $ignored) {
        $queue->push(new ApmBacklogJob, '', 'default');
    }

    $queue->later(300, new ApmBacklogJob, '', 'default');
    $queue->later(300, new ApmBacklogJob, '', 'default');
    expect($queue->pop('default'))->not->toBeNull(); // reserves the oldest

    $this->travel(30)->seconds();

    [$metrics, $exporter] = backlogMetrics();
    $observer = backlogObserver(['database' => ['default', 'mail']]);
    $observer->start($metrics);

    expect($observer->sample())->toBeTrue()
        ->and($observer->reporting())->toBeTrue();

    $exported = backlogExport($metrics, $exporter);

    expect(array_keys($exported))->toEqualCanonicalizing(BACKLOG_GAUGES)
        ->and(backlogValue($exported['fledge.queue.pending'], 'default'))->toBe(2)
        ->and(backlogValue($exported['fledge.queue.delayed'], 'default'))->toBe(2)
        ->and(backlogValue($exported['fledge.queue.reserved'], 'default'))->toBe(1)
        ->and(backlogValue($exported['fledge.queue.oldest_age'], 'default'))->toBeGreaterThanOrEqual(30.0)->toBeLessThan(32.0)
        ->and(backlogValue($exported['fledge.queue.pending'], 'mail'))->toBe(0)
        ->and(backlogValue($exported['fledge.queue.delayed'], 'mail'))->toBe(0)
        ->and(backlogValue($exported['fledge.queue.reserved'], 'mail'))->toBe(0)
        ->and(backlogValue($exported['fledge.queue.oldest_age'], 'mail'))->toBeNull() // empty queue: absent, not zero
        ->and($exported['fledge.queue.oldest_age'])->toHaveCount(1);

    foreach (BACKLOG_GAUGES as $name) {
        foreach ($exported[$name] as $point) {
            expect(array_keys($point['attributes']))->toEqualCanonicalizing(['messaging.system', 'messaging.destination.name', 'laravel.queue.connection'])
                ->and($point['attributes']['messaging.system'])->toBe('laravel-queue')
                ->and($point['attributes']['laravel.queue.connection'])->toBe('database');
        }
    }

    $metrics->collect();

    foreach ($exporter->collect(true) as $metric) {
        if (in_array($metric->name, BACKLOG_GAUGES, true)) {
            expect($metric->unit)->toBe($metric->name === 'fledge.queue.oldest_age' ? 's' : '{job}');
        }
    }

    $observer->stop();
    $metrics->stop();
});

it('omits oldest_age while nothing is pending', function () {
    backlogDatabase();
    app('queue')->connection('database')->later(300, new ApmBacklogJob, '', 'default');

    [$metrics, $exporter] = backlogMetrics();
    $observer = backlogObserver();
    $observer->start($metrics);
    $observer->sample();

    $exported = backlogExport($metrics, $exporter);

    expect($exported)->not->toHaveKey('fledge.queue.oldest_age')
        ->and(backlogValue($exported['fledge.queue.pending'], 'default'))->toBe(0)
        ->and(backlogValue($exported['fledge.queue.delayed'], 'default'))->toBe(1);

    $observer->stop();
    $metrics->stop();
});

it('lets exactly one of two observers sharing a lock store report, and keeps the same reporter', function () {
    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');

    [$metricsA, $exporterA] = backlogMetrics();
    [$metricsB, $exporterB] = backlogMetrics();
    $a = backlogObserver();
    $b = backlogObserver();

    // A 1 s interval: the lease outlasts the next renewal by a second.
    expect($a->ttl())->toBe(3)
        ->and($a->lease())->toBe(2.0);

    $a->start($metricsA);
    $b->start($metricsB);

    foreach (range(0, 4) as $interval) {
        // Alternate who samples first: the holder keeps the lock either way.
        $order = $interval % 2 === 0 ? [$a, $b] : [$b, $a];

        foreach ($order as $observer) {
            $observer->sample();
        }

        $exportedA = backlogExport($metricsA, $exporterA);
        $exportedB = backlogExport($metricsB, $exporterB);

        expect($a->reporting())->toBeTrue()
            ->and($b->reporting())->toBeFalse()
            ->and(array_keys($exportedA))->toEqualCanonicalizing(BACKLOG_GAUGES)
            ->and($exportedA['fledge.queue.pending'])->toHaveCount(1)
            ->and($exportedB)->toBe([]); // nothing, not zero

        $this->travel(1)->seconds();
    }

    $a->stop();
    $b->stop();
    $metricsA->stop();
    $metricsB->stop();
});

it('hands over to a standby once a dead holder lock expires, without a double', function () {
    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');

    [$metricsA, $exporterA] = backlogMetrics();
    [$metricsB, $exporterB] = backlogMetrics();
    $a = backlogObserver();
    $b = backlogObserver();
    $a->start($metricsA);
    $b->start($metricsB);

    expect($a->sample())->toBeTrue()
        ->and($b->sample())->toBeFalse();

    // A dies: it samples no more and never releases the lock.
    $this->travel(1)->seconds();

    expect($b->sample())->toBeFalse()
        ->and(backlogExport($metricsB, $exporterB))->toBe([]);

    $this->travel(3)->seconds(); // past A's TTL of 3 s

    expect($b->sample())->toBeTrue()
        ->and($a->reporting())->toBeFalse()
        ->and(backlogExport($metricsA, $exporterA))->toBe([])
        ->and(backlogExport($metricsB, $exporterB)['fledge.queue.pending'])->toHaveCount(1);

    $a->stop();
    $b->stop();
    $metricsA->stop();
    $metricsB->stop();
});

it('releases the lock on stop so a standby takes over at its next sample', function () {
    backlogDatabase();

    [$metricsA] = backlogMetrics();
    [$metricsB, $exporterB] = backlogMetrics();
    $a = backlogObserver();
    $b = backlogObserver();
    $a->start($metricsA);
    $b->start($metricsB);

    expect($a->sample())->toBeTrue()
        ->and($b->sample())->toBeFalse();

    $a->stop();

    expect($b->sample())->toBeTrue()
        ->and(backlogExport($metricsB, $exporterB))->toHaveKey('fledge.queue.pending');

    $b->stop();
    $metricsA->stop();
    $metricsB->stop();
});

it('reports from no process and logs a warning once when the lock store cannot lock', function (string $kind) {
    $store = $kind === 'session'
        ? static fn (): Repository => new Repository(new SessionStore(new SessionStoreBackend('backlog', new ArraySessionHandler(10))))
        : static fn (): Repository => new Repository(new NullStore);

    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');

    [$log, $handler] = backlogLog();
    [$metrics, $exporter] = backlogMetrics();
    $observer = backlogObserver(store: $store, log: $log);
    $observer->start($metrics);

    expect($observer->sample())->toBeFalse()
        ->and($observer->sample())->toBeFalse()
        ->and($observer->reporting())->toBeFalse()
        ->and(backlogExport($metrics, $exporter))->toBe([]);

    $warnings = array_filter($handler->getRecords(), static fn ($record): bool => str_contains($record->message, 'does not support locks'));

    expect($warnings)->toHaveCount(1);

    $observer->stop();
    $metrics->stop();
})->with([
    'a store without LockProvider' => ['session'],
    'the null store, whose locks always succeed' => ['null'],
]);

it('keeps reporting the healthy queues and logs when a connection throws', function () {
    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');

    config()->set('queue.connections.unconfigured_db', ['driver' => 'database', 'connection' => 'missing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('queue.connections.missing_table', ['driver' => 'database', 'connection' => null, 'table' => 'no_such_jobs', 'queue' => 'default', 'retry_after' => 90]);

    [$log, $handler] = backlogLog();
    [$metrics, $exporter] = backlogMetrics();
    $observer = backlogObserver(['unconfigured_db' => ['default'], 'missing_table' => ['default'], 'database' => ['default']], log: $log);
    $observer->start($metrics);

    expect($observer->sample())->toBeTrue()
        ->and($metrics->collect())->toBeTrue();

    $points = [];

    foreach ($exporter->collect(true) as $metric) {
        foreach ($metric->data->dataPoints as $point) {
            $points[$metric->name][] = $point->attributes->toArray()['laravel.queue.connection'];
        }
    }

    expect($points['fledge.queue.pending'])->toBe(['database'])
        ->and(implode("\n", array_map(static fn ($record): string => $record->message, $handler->getRecords())))
        ->toContain('unconfigured_db')
        ->toContain('missing_table:default');

    $observer->stop();
    $metrics->stop();
});

it('reads the configured queues and scopes the lock key by service and environment', function () {
    $config = [
        'service' => ['name' => 'My App', 'environment' => 'staging'],
        'metrics' => ['groups' => ['queue_backlog' => true], 'queue_backlog' => ['queues' => ['redis' => ['default', ' ', 'mail', 'mail'], 'empty' => []]]],
    ];

    expect(QueueBacklogObserver::configuredQueues($config))->toBe(['redis' => ['default', 'mail']])
        ->and(QueueBacklogObserver::lockKeyFor($config))->toBe('fledge-apm:queue-backlog:My-App:staging')
        ->and(QueueBacklogObserver::lockKeyFor(['service' => ['name' => 'My App', 'environment' => 'production']]))->not->toBe(QueueBacklogObserver::lockKeyFor($config))
        ->and(QueueBacklogObserver::configuredQueues(array_replace_recursive($config, ['metrics' => ['groups' => ['queue_backlog' => false]]])))->toBe([])
        ->and(QueueBacklogObserver::ttlFor(60))->toBe(90)
        ->and(QueueBacklogObserver::ttlFor(10))->toBe(15)
        ->and(QueueBacklogObserver::ttlFor(120))->toBe(150)
        ->and(QueueBacklogObserver::leaseFor(60))->toBe(75.0)
        ->and(QueueBacklogObserver::ttlFor(1))->toBe(3)
        ->and(QueueBacklogObserver::leaseFor(1))->toBe(2.0);
});

/**
 * Register and boot the provider with metrics on; Metrics exports into the
 * returned in-memory exporter.
 *
 * @param  array<string, mixed>  $metricsConfig
 * @return array{Metrics, InMemoryExporter}
 */
function backlogApm(array $metricsConfig, string $sapi = 'cli'): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.exporter', 'memory');
    config()->set('apm.breaker.enabled', false);
    config()->set('apm.instrumentation', array_fill_keys(array_keys(ApmServiceProvider::INSTRUMENTATIONS), false));
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true], $metricsConfig));

    $provider = new ApmServiceProvider(app());
    $provider->register();

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    app()->instance(Metrics::class, new Metrics(config('apm'), $exporter, storage: app(FiberContextStorage::class), sapi: $sapi));

    $provider->boot();

    return [app(Metrics::class), $exporter];
}

it('starts the observer from the provider once a queue worker is detected', function () {
    backlogDatabase();
    app('queue')->connection('database')->push(new ApmBacklogJob, '', 'default');

    [$metrics, $exporter] = backlogApm(['queue_backlog' => ['queues' => ['database' => ['default']], 'lock_store' => 'array']]);

    expect(app()->bound(QueueBacklogObserver::class))->toBeFalse();

    // A stock queue:work: WorkerStarting, a loop turn for its signal
    // watchers, then the first Looping, all from {main}.
    app('events')->dispatch(new WorkerStarting('database', 'default', null));

    expect(app()->bound(QueueBacklogObserver::class))->toBeTrue();

    $observer = app(QueueBacklogObserver::class);
    delay(0);

    expect($observer->reporting())->toBeFalse();

    app('events')->dispatch(new Looping('database', 'default'));
    $observer->sample();

    expect($observer->reporting())->toBeTrue()
        ->and($observer->lockKey())->toBe(QueueBacklogObserver::lockKeyFor(config('apm')))
        ->and(backlogValue(backlogExport($metrics, $exporter)['fledge.queue.pending'] ?? [], 'default'))->toBe(1);

    $observer->stop();
    $metrics->stop();
});

it('registers nothing for an empty queues map, with the group off, in a short command or per request', function (array $metricsConfig, string $sapi, bool $looping) {
    backlogDatabase();

    [$metrics, $exporter] = backlogApm($metricsConfig, $sapi);

    if ($looping) {
        app('events')->dispatch(new Looping('database', 'default'));
    }

    app()->terminate();

    expect(app()->bound(QueueBacklogObserver::class))->toBeFalse()
        ->and(array_intersect(array_keys(backlogExport($metrics, $exporter)), BACKLOG_GAUGES))->toBe([]);

    $metrics->stop();
})->with([
    'empty queues' => [['queue_backlog' => ['queues' => []]], 'cli', true],
    'group off' => [['groups' => ['queue_backlog' => false], 'queue_backlog' => ['queues' => ['database' => ['default']]]], 'cli', true],
    'short artisan command' => [['queue_backlog' => ['queues' => ['database' => ['default']]]], 'cli', false],
    'per request with metrics.fpm on' => [['fpm' => true, 'queue_backlog' => ['queues' => ['database' => ['default']]]], 'fpm-fcgi', false],
]);
