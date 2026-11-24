<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Metrics\QueueBacklogObserver;
use Fledge\Apm\Metrics\RuntimeObserver;
use Fledge\Apm\Tests\Fixtures\Metrics\SlowMetricExporter;
use Fledge\Loop;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * Which timers and samples each kind of process gets: a self-driven process
 * (torque's master, collectDue()), a daemon marked before its loop starts,
 * a stock queue worker, and the due check of the collect timer.
 */

final class ApmKindsJob
{
    public function handle(): void {}
}

const KINDS_LOOP_NAMES = ['fledge.loop.lag', 'fledge.loop.watchers'];
const KINDS_BACKLOG_NAMES = ['fledge.queue.pending', 'fledge.queue.delayed', 'fledge.queue.reserved', 'fledge.queue.oldest_age'];

/**
 * Bring the event loop back to "not running", as in a process that has
 * not awaited anything yet.
 */
function kindsIdleLoop(): void
{
    if (Loop::getDriver()->isRunning()) {
        $referenced = array_filter(Loop::getIdentifiers(), fn (string $id): bool => Loop::isEnabled($id) && Loop::isReferenced($id));

        expect($referenced)->toBe([]);

        Loop::run();
    }

    expect(Loop::getDriver()->isRunning())->toBeFalse();
}

/**
 * The database queue with one pending job on the `default` queue.
 */
function kindsQueue(): void
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

    app('queue')->connection('database')->push(new ApmKindsJob, '', 'default');
}

/**
 * Register and boot the provider with metrics and the backlog of
 * database:default on; Metrics exports into the returned exporter.
 *
 * @return array{Metrics, InMemoryExporter}
 */
function kindsBoot(int $interval): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.exporter', 'memory');
    config()->set('apm.breaker.enabled', false);
    config()->set('apm.instrumentation', array_fill_keys(array_keys(ApmServiceProvider::INSTRUMENTATIONS), false));
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), [
        'enabled' => true,
        'interval' => $interval,
        'groups' => ['database_pool' => false],
        'queue_backlog' => ['queues' => ['database' => ['default']], 'lock_store' => 'array'],
    ]));

    $provider = new ApmServiceProvider(app());
    $provider->register();

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    app()->instance(Metrics::class, new Metrics(config('apm'), $exporter, storage: app(FiberContextStorage::class)));

    $provider->boot();

    return [app(Metrics::class), $exporter];
}

/**
 * Names of every metric exported so far with at least one data point.
 *
 * @return list<string>
 */
function kindsExportedNames(InMemoryExporter $exporter): array
{
    $names = [];

    foreach ($exporter->collect() as $metric) {
        foreach ($metric->data->dataPoints as $ignored) {
            $names[] = $metric->name;

            break;
        }
    }

    return array_values(array_unique($names));
}

/**
 * Whether the backlog lock is free: a fresh owner can take it.
 */
function kindsLockFree(): bool
{
    $lock = app('cache')->store('array')->lock(QueueBacklogObserver::lockKeyFor(config('apm')), 10);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

/**
 * The loop timer id of a backlog or runtime observer, null when none is armed.
 */
function kindsTimer(object $observer): ?string
{
    return (fn (): ?string => $this->timer)->call($observer);
}

/**
 * The collect timer id of the application's Metrics, null when it armed none.
 */
function kindsCollectTimer(): ?string
{
    return Closure::bind(
        static fn (): ?string => Metrics::$timerOwner?->get() === app(Metrics::class) ? Metrics::$timer : null,
        null,
        Metrics::class,
    )();
}

afterEach(function () {
    foreach ([QueueBacklogObserver::class, RuntimeObserver::class] as $class) {
        if (app()->bound($class)) {
            app($class)->stop();
        }
    }

    if (app()->bound(Metrics::class)) {
        app(Metrics::class)->stop();
    }
});

it('never samples the backlog nor records loop metrics in a process that drives its own collects', function (string $order) {
    kindsIdleLoop(); // torque's master between its awaits
    kindsQueue();
    [$metrics, $exporter] = kindsBoot(1);

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'jobs')) {
            $queries++;
        }
    });

    match ($order) {
        'mark, then collectDue' => (function (): void {
            Metrics::markLongRunning('torque');
            Metrics::collectDue();
        })(),
        'collectDue, then mark' => (function (): void {
            Metrics::collectDue();
            Metrics::markLongRunning('torque');
        })(),
        // The hook starts the observer inside a fiber, which queues a sample
        // fiber and arms the timers; collectDue() comes before they run.
        'mark inside a fiber, collectDue before its sample runs' => async(function (): void {
            Metrics::markLongRunning('torque');
            Metrics::collectDue();
        })->await(),
    };

    delay(0); // the master awaits Redis: queued starts and sample fibers run
    (function (): void {
        $this->lastCollect = hrtime(true) - 1_100_000_000; // one interval passed
    })->call($metrics);
    Metrics::collectDue(); // the next maintenance tick exports

    expect($metrics->selfDriven())->toBeTrue()
        ->and($queries)->toBe(0)
        ->and(kindsLockFree())->toBeTrue()
        ->and(app()->bound(RuntimeObserver::class))->toBeTrue()
        ->and(kindsTimer(app(RuntimeObserver::class)))->toBeNull()
        ->and(kindsCollectTimer())->toBeNull();

    if (app()->bound(QueueBacklogObserver::class)) {
        expect(app(QueueBacklogObserver::class)->reporting())->toBeFalse();
    }

    $names = kindsExportedNames($exporter);

    expect($names)->toContain('fledge.php.memory.usage')
        ->and(array_intersect($names, [...KINDS_LOOP_NAMES, ...KINDS_BACKLOG_NAMES]))->toBe([]);
})->with([
    'mark, then collectDue',
    'collectDue, then mark',
    'mark inside a fiber, collectDue before its sample runs',
]);

it('arms the lag and backlog timers once the loop turns in a process marked before its loop started', function () {
    kindsIdleLoop(); // a daemon before Loop::run()
    kindsQueue();
    [$metrics, $exporter] = kindsBoot(2);

    Metrics::markLongRunning('resonate');

    $backlog = app(QueueBacklogObserver::class);
    $runtime = app(RuntimeObserver::class);

    // From {main}: nothing sampled, nothing armed yet.
    expect($backlog->reporting())->toBeFalse()
        ->and(kindsTimer($backlog))->toBeNull()
        ->and(kindsTimer($runtime))->toBeNull()
        ->and(kindsCollectTimer())->toBeNull()
        ->and(kindsLockFree())->toBeTrue();

    delay(0); // the first loop turn: every timer arms and the first sample is queued
    delay(0); // the sample fiber runs

    expect($backlog->reporting())->toBeTrue()
        ->and(kindsTimer($backlog))->not->toBeNull()
        ->and(kindsTimer($runtime))->not->toBeNull()
        ->and(kindsCollectTimer())->not->toBeNull();

    $metrics->wait();
    $metrics->collect();

    // The lag values themselves are covered by the RuntimeObserver tests.
    expect(kindsExportedNames($exporter))->toContain('fledge.loop.watchers', 'fledge.queue.pending');
});

it('arms no timer and samples nothing before the first Looping of a stock worker', function () {
    kindsIdleLoop(); // a stock queue:work before its first job
    kindsQueue();
    [$metrics, $exporter] = kindsBoot(1);

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'jobs')) {
            $queries++;
        }
    });

    app('events')->dispatch(new WorkerStarting('database', 'default', null));

    $backlog = app(QueueBacklogObserver::class);
    $runtime = app(RuntimeObserver::class);

    // Worker::daemon turns the loop from {main} for its signal watchers
    // before the first Looping: nothing arms, nothing samples.
    delay(0);

    expect(kindsTimer($backlog))->toBeNull()
        ->and(kindsTimer($runtime))->toBeNull()
        ->and(kindsCollectTimer())->toBeNull()
        ->and($queries)->toBe(0)
        ->and($backlog->reporting())->toBeFalse()
        ->and(kindsLockFree())->toBeTrue();

    // The first Looping samples inline and arms nothing.
    app('events')->dispatch(new Looping('database', 'default'));
    delay(0);

    expect($metrics->eventDriven())->toBeTrue()
        ->and($queries)->toBeGreaterThan(0)
        ->and($backlog->reporting())->toBeTrue()
        ->and(kindsTimer($backlog))->toBeNull()
        ->and(kindsTimer($runtime))->toBeNull()
        ->and(kindsCollectTimer())->toBeNull();

    $metrics->collect();

    expect(kindsExportedNames($exporter))->toContain('fledge.queue.pending')
        ->not->toContain('fledge.loop.watchers');
});

it('does not export twice when the timer ticks right after a Looping collect', function () {
    config()->set('apm.enabled', true);
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true, 'interval' => 10]));

    $slow = new SlowMetricExporter(0.0);
    $metrics = new Metrics(config('apm'), $slow);
    $metrics->detect(Metrics::ROLE_QUEUE);
    $counter = $metrics->provider()->getMeter('tests')->createCounter('fledge.test.count');
    $backdate = fn (float $seconds) => (function () use ($seconds): void {
        $this->lastCollect = hrtime(true) - (int) ($seconds * 1_000_000_000);
    })->call($metrics);
    $tick = fn () => (fn () => $this->tick())->call($metrics);

    $counter->add(1);
    $backdate(11);

    expect($metrics->collectIfDue())->toBeTrue() // the Looping collect
        ->and($slow->exports)->toBe(1);

    $counter->add(1);
    $tick(); // a late tick right after it

    expect($slow->exports)->toBe(1);

    $backdate(9.5); // within a tenth of an interval of due: a slightly early tick still collects
    $tick();

    expect($slow->exports)->toBe(2);

    $metrics->stop();
});
