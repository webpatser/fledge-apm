<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Metrics\RuntimeObserver;
use Fledge\Fiber\Database\Native\NativePdoPool;
use Fledge\Fiber\Database\PoolStatsRegistry;
use Fledge\Loop;
use Fledge\Loop\CallbackType;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

use function Fledge\Async\async;
use function Fledge\Async\delay;

const RUNTIME_LOOP_NAMES = ['fledge.loop.lag', 'fledge.loop.watchers'];
const RUNTIME_PHP_NAMES = ['fledge.php.memory.usage', 'fledge.php.memory.peak', 'fledge.php.gc.runs', 'fledge.php.gc.collected', 'fledge.php.gc.duration'];
const RUNTIME_POOL_NAMES = ['db.client.connection.count', 'db.client.connection.max', 'db.client.connection.pending_requests'];

/**
 * A long-running Metrics instance exporting into an in-memory exporter.
 *
 * @return array{Metrics, InMemoryExporter}
 */
function runtimeMetrics(): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true, 'interval' => 1]));

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    $metrics = new Metrics(config('apm'), $exporter);
    $metrics->detect();

    return [$metrics, $exporter];
}

/**
 * Start the observer from inside a fiber, where the event loop is running
 * (from {main} the driver does not report itself as running).
 */
function runtimeStart(RuntimeObserver $observer, Metrics $metrics): void
{
    $GLOBALS['runtimeObservers'][] = $observer;

    async(static fn () => $observer->start($metrics))->await();
}

/**
 * Collect once. Per metric name: kind (data class), unit and data points; a
 * metric without data points is absent.
 *
 * @return array<string, array{kind: class-string, unit: ?string, monotonic: ?bool, points: list<object>}>
 */
function runtimeExport(Metrics $metrics, InMemoryExporter $exporter): array
{
    $metrics->collect();

    $exported = [];

    foreach ($exporter->collect(true) as $metric) {
        $points = [];

        foreach ($metric->data->dataPoints as $point) {
            $points[] = $point;
        }

        if ($points !== []) {
            $exported[$metric->name] = [
                'kind' => $metric->data::class,
                'unit' => $metric->unit,
                'monotonic' => $metric->data instanceof Sum ? $metric->data->monotonic : null,
                'points' => $points,
            ];
        }
    }

    return $exported;
}

/**
 * The data point values per attribute set key (the attribute values joined).
 *
 * @param  array{points: list<object>}  $metric
 * @return array<string, int|float>
 */
function runtimeValues(array $metric): array
{
    $values = [];

    foreach ($metric['points'] as $point) {
        $values[implode('|', array_map('strval', $point->attributes->toArray()))] = $point->value;
    }

    return $values;
}

/**
 * Real pools with real PDO connections: two share the connection name
 * `runtime-main`, one is `runtime-other`.
 *
 * @return list<NativePdoPool> keep them referenced, the registry holds them weakly
 */
function runtimePools(): array
{
    $factory = static fn (): PDO => new PDO('sqlite::memory:');

    $first = new NativePdoPool($factory, 4, 0.0);
    $second = new NativePdoPool($factory, 3, 0.0);
    $other = new NativePdoPool($factory, 2, 0.0);

    PoolStatsRegistry::register($first, 'runtime-main');
    PoolStatsRegistry::register($second, 'runtime-main');
    PoolStatsRegistry::register($other, 'runtime-other');

    $first->acquire();
    $first->release($first->acquire()); // first: 2 open, 1 idle
    $second->acquire();                 // second: 1 open, 0 idle
    $other->release($other->acquire()); // other: 1 open, 1 idle

    return [$first, $second, $other];
}

afterEach(function () {
    // Stopped here too, so no timer of a failed test fires inside a later one.
    foreach ($GLOBALS['runtimeObservers'] ?? [] as $observer) {
        $observer->stop();
    }

    $GLOBALS['runtimeObservers'] = [];

    if (app()->bound(RuntimeObserver::class)) {
        app(RuntimeObserver::class)->stop();
    }

    gc_collect_cycles();
});

it('creates every instrument with its exact name, unit and kind', function () {
    if (! RuntimeObserver::poolRegistryAvailable()) {
        $this->markTestSkipped('The installed fledge-fiber has no pool registry.');
    }

    delay(0); // the event loop now runs
    [$metrics, $exporter] = runtimeMetrics();
    $pools = runtimePools();

    $observer = new RuntimeObserver(lagPeriod: 0.05);
    runtimeStart($observer, $metrics);
    delay(0.2);

    $exported = runtimeExport($metrics, $exporter);

    expect(array_keys($exported))->toEqualCanonicalizing([...RUNTIME_LOOP_NAMES, ...RUNTIME_PHP_NAMES, ...RUNTIME_POOL_NAMES]);

    $expected = [
        'fledge.loop.lag' => [Histogram::class, 's'],
        'fledge.loop.watchers' => [Gauge::class, '{watcher}'],
        'fledge.php.memory.usage' => [Gauge::class, 'By'],
        'fledge.php.memory.peak' => [Gauge::class, 'By'],
        'fledge.php.gc.runs' => [Sum::class, '{run}'],
        'fledge.php.gc.collected' => [Sum::class, '{object}'],
        'fledge.php.gc.duration' => [Sum::class, 's'],
        'db.client.connection.count' => [Gauge::class, '{connection}'],
        'db.client.connection.max' => [Gauge::class, '{connection}'],
        'db.client.connection.pending_requests' => [Gauge::class, '{request}'],
    ];

    foreach ($expected as $name => [$kind, $unit]) {
        expect($exported[$name]['kind'])->toBe($kind, $name)
            ->and($exported[$name]['unit'])->toBe($unit, $name);
    }

    foreach (['fledge.php.gc.runs', 'fledge.php.gc.collected', 'fledge.php.gc.duration'] as $counter) {
        expect($exported[$counter]['monotonic'])->toBeTrue($counter);
    }

    $observer->stop();
    $metrics->stop();
    unset($pools);
});

it('records the loop lag as a histogram with explicit boundaries from an unreferenced timer', function () {
    delay(0);
    [$metrics, $exporter] = runtimeMetrics();

    $observer = new RuntimeObserver(runtime: false, databasePool: false, lagPeriod: 0.05);
    runtimeStart($observer, $metrics);
    delay(0.4);

    $exported = runtimeExport($metrics, $exporter);
    $point = $exported['fledge.loop.lag']['points'][0];

    expect($point->explicitBounds)->toBe([0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5])
        ->and($point->count)->toBeGreaterThanOrEqual(3)
        ->and($point->min)->toBeGreaterThanOrEqual(0.0)
        ->and($point->attributes->toArray())->toBe([]);

    $observer->stop();
    $metrics->stop();
});

it('cancels the lag timer on stop', function () {
    delay(0);
    [$metrics] = runtimeMetrics();

    $repeats = static fn (): int => count(array_filter(
        Loop::getIdentifiers(),
        static fn (string $id): bool => Loop::getType($id) === CallbackType::Repeat,
    ));
    $before = $repeats();

    $observer = new RuntimeObserver(runtime: false, databasePool: false, lagPeriod: 0.05);
    runtimeStart($observer, $metrics);

    expect($repeats())->toBe($before + 1);

    $observer->stop();

    expect($repeats())->toBe($before);

    $metrics->stop();
});

it('counts the loop watchers by type, every type reported', function () {
    delay(0);
    [$metrics, $exporter] = runtimeMetrics();

    $observer = new RuntimeObserver(runtime: false, databasePool: false, lagPeriod: 0.05);
    runtimeStart($observer, $metrics);

    $timer = Loop::unreference(Loop::delay(60, static function (): void {}));
    $exported = runtimeExport($metrics, $exporter);
    $counts = [];

    foreach ($exported['fledge.loop.watchers']['points'] as $point) {
        expect(array_keys($point->attributes->toArray()))->toBe(['fledge.loop.watcher.type']);
        $counts[$point->attributes->toArray()['fledge.loop.watcher.type']] = $point->value;
    }

    expect(array_keys($counts))->toEqualCanonicalizing(['defer', 'delay', 'repeat', 'readable', 'writable', 'signal'])
        ->and($counts['delay'])->toBeGreaterThanOrEqual(1)
        ->and($counts['repeat'])->toBeGreaterThanOrEqual(1); // the lag timer itself

    Loop::cancel($timer);
    $observer->stop();
    $metrics->stop();
});

it('reports memory and the garbage collector without a loop dependency', function () {
    delay(0);
    [$metrics, $exporter] = runtimeMetrics();

    $cycle = new stdClass;
    $cycle->self = $cycle;
    unset($cycle);
    gc_collect_cycles();

    $observer = new RuntimeObserver(loop: false, databasePool: false);
    runtimeStart($observer, $metrics);

    $exported = runtimeExport($metrics, $exporter);

    expect(array_keys($exported))->toEqualCanonicalizing(RUNTIME_PHP_NAMES)
        ->and(array_values(runtimeValues($exported['fledge.php.memory.usage']))[0])->toBeGreaterThan(0)
        ->and(array_values(runtimeValues($exported['fledge.php.memory.peak']))[0])->toBeGreaterThan(0)
        ->and(array_values(runtimeValues($exported['fledge.php.gc.runs']))[0])->toBeGreaterThanOrEqual(0)
        ->and(array_values(runtimeValues($exported['fledge.php.gc.collected']))[0])->toBeGreaterThanOrEqual(0)
        ->and(array_values(runtimeValues($exported['fledge.php.gc.duration']))[0])->toBeGreaterThanOrEqual(0.0);

    foreach (RUNTIME_PHP_NAMES as $name) {
        expect($exported[$name]['points'])->toHaveCount(1)
            ->and($exported[$name]['points'][0]->attributes->toArray())->toBe([]);
    }

    $observer->stop();
    $metrics->stop();
});

it('sums the pools per connection name and splits idle from used', function () {
    if (! RuntimeObserver::poolRegistryAvailable()) {
        $this->markTestSkipped('The installed fledge-fiber has no pool registry.');
    }

    [$metrics, $exporter] = runtimeMetrics();

    $pools = runtimePools();

    $observer = new RuntimeObserver(loop: false, runtime: false);
    runtimeStart($observer, $metrics);

    $exported = runtimeExport($metrics, $exporter);
    $count = runtimeValues($exported['db.client.connection.count']);
    $max = runtimeValues($exported['db.client.connection.max']);
    $pending = runtimeValues($exported['db.client.connection.pending_requests']);

    expect(array_keys($exported))->toEqualCanonicalizing(RUNTIME_POOL_NAMES)
        ->and($count['runtime-main|idle'])->toBe(1)
        ->and($count['runtime-main|used'])->toBe(2)
        ->and($count['runtime-other|idle'])->toBe(1)
        ->and($count['runtime-other|used'])->toBe(0)
        ->and($max['runtime-main'])->toBe(7)
        ->and($max['runtime-other'])->toBe(2)
        ->and($pending['runtime-main'])->toBe(0);

    foreach ($exported['db.client.connection.count']['points'] as $point) {
        expect(array_keys($point->attributes->toArray()))->toBe(['db.client.connection.pool.name', 'db.client.connection.state']);
    }

    foreach ($exported['db.client.connection.max']['points'] as $point) {
        expect(array_keys($point->attributes->toArray()))->toBe(['db.client.connection.pool.name']);
    }

    $observer->stop();
    $metrics->stop();
    unset($pools);
});

it('records nothing for a group that is switched off', function (array $off, array $absent) {
    if (! RuntimeObserver::poolRegistryAvailable()) {
        $this->markTestSkipped('The installed fledge-fiber has no pool registry.');
    }

    delay(0);
    [$metrics, $exporter] = runtimeMetrics();
    $pool = new NativePdoPool(static fn (): PDO => new PDO('sqlite::memory:'), 2, 0.0);
    PoolStatsRegistry::register($pool, 'runtime-toggle');
    $pool->release($pool->acquire());

    $observer = new RuntimeObserver(
        loop: ! in_array('loop', $off, true),
        runtime: ! in_array('runtime', $off, true),
        databasePool: ! in_array('database_pool', $off, true),
        lagPeriod: 0.05,
    );
    runtimeStart($observer, $metrics);
    delay(0.2);

    $exported = array_keys(runtimeExport($metrics, $exporter));

    expect(array_intersect($exported, $absent))->toBe([])
        ->and(array_diff([...RUNTIME_LOOP_NAMES, ...RUNTIME_PHP_NAMES, ...RUNTIME_POOL_NAMES], $absent, $exported))->toBe([]);

    $observer->stop();
    $metrics->stop();
    unset($pool);
})->with([
    'loop off' => [['loop'], RUNTIME_LOOP_NAMES],
    'runtime off' => [['runtime'], RUNTIME_PHP_NAMES],
    'database_pool off' => [['database_pool'], RUNTIME_POOL_NAMES],
    'all off' => [['loop', 'runtime', 'database_pool'], [...RUNTIME_LOOP_NAMES, ...RUNTIME_PHP_NAMES, ...RUNTIME_POOL_NAMES]],
]);

it('stops recording after stop()', function () {
    delay(0);
    [$metrics, $exporter] = runtimeMetrics();

    $observer = new RuntimeObserver(databasePool: false, lagPeriod: 0.05);
    runtimeStart($observer, $metrics);
    $observer->stop();
    delay(0.2);

    expect(runtimeExport($metrics, $exporter))->toBe([]);

    $metrics->stop();
});

it('reads the groups from the config, each on by default', function () {
    expect(RuntimeObserver::groups([]))->toBe(['loop' => true, 'runtime' => true, 'database_pool' => true])
        ->and(RuntimeObserver::groups(['metrics' => ['groups' => ['loop' => false, 'database_pool' => false]]]))
        ->toBe(['loop' => false, 'runtime' => true, 'database_pool' => false]);
});

/**
 * @param  array<string, mixed>  $metricsConfig
 * @return array{Metrics, InMemoryExporter}
 */
function runtimeApm(array $metricsConfig, string $sapi = 'cli'): array
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

it('starts the observer from the provider once a queue worker is detected, without loop metrics in a stock worker', function () {
    delay(0); // an earlier await left the loop fiber suspended: the driver says "running"
    [$metrics, $exporter] = runtimeApm([]);

    expect(app()->bound(RuntimeObserver::class))->toBeFalse();

    // A stock queue:work from {main}: WorkerStarting, then the worker turns
    // the loop for its signal watchers before the first Looping.
    app('events')->dispatch(new WorkerStarting('database', 'default', null));

    expect(app()->bound(RuntimeObserver::class))->toBeTrue();

    $observer = app(RuntimeObserver::class);
    $armed = fn (): array => [
        (fn (): ?string => $this->timer)->call($observer),
        (fn (): ?string => $this->pendingArm)->call($observer),
    ];

    delay(0);

    expect($armed())->toBe([null, null]);

    app('events')->dispatch(new Looping('database', 'default'));
    delay(0); // the next job awaits

    $exported = runtimeExport($metrics, $exporter);

    expect($exported)->toHaveKeys(RUNTIME_PHP_NAMES)
        ->and(array_intersect(array_keys($exported), RUNTIME_LOOP_NAMES))->toBe([])
        ->and($armed())->toBe([null, null]);

    $observer->stop();
    $metrics->stop();
});

it('records no loop metrics in a process with no fiber and no loop turn', function () {
    // A daemon marked from {main}: the lag timer waits for a loop turn.
    [$daemon] = runtimeMetrics();
    $daemon->detect('daemon', explicit: true);
    $waiting = new RuntimeObserver(databasePool: false, lagPeriod: 0.05);
    $GLOBALS['runtimeObservers'][] = $waiting;
    $waiting->start($daemon);
    usleep(200_000); // four lag periods without a turn

    expect((fn (): ?string => $this->timer)->call($waiting))->toBeNull()
        ->and((fn (): ?string => $this->pendingArm)->call($waiting))->not->toBeNull();

    $waiting->stop();

    expect((fn (): ?string => $this->pendingArm)->call($waiting))->toBeNull();

    // A process whose host events arrive outside any fiber: nothing is
    // armed, so even the loop turn of its own collect records no loop metrics.
    [$metrics, $exporter] = runtimeMetrics();
    $metrics->hostEvent();

    $observer = new RuntimeObserver(databasePool: false, lagPeriod: 0.05);
    $GLOBALS['runtimeObservers'][] = $observer;
    $observer->start($metrics);
    usleep(200_000);

    $exported = runtimeExport($metrics, $exporter);

    expect($exported)->toHaveKeys(RUNTIME_PHP_NAMES)
        ->and(array_intersect(array_keys($exported), RUNTIME_LOOP_NAMES))->toBe([])
        ->and((fn (): ?string => $this->timer)->call($observer))->toBeNull();

    $observer->stop();
    $metrics->stop();
    $daemon->stop();
});

it('registers nothing in a short command, per request, or with every group off', function (array $metricsConfig, string $sapi, bool $looping) {
    [$metrics, $exporter] = runtimeApm($metricsConfig, $sapi);

    if ($looping) {
        app('events')->dispatch(new Looping('database', 'default'));
    }

    app()->terminate();

    expect(app()->bound(RuntimeObserver::class))->toBeFalse()
        ->and(array_intersect(array_keys(runtimeExport($metrics, $exporter)), [...RUNTIME_LOOP_NAMES, ...RUNTIME_PHP_NAMES, ...RUNTIME_POOL_NAMES]))->toBe([]);

    $metrics->stop();
})->with([
    'short artisan command' => [[], 'cli', false],
    'per request with metrics.fpm on' => [['fpm' => true], 'fpm-fcgi', false],
    'every group off' => [['groups' => ['loop' => false, 'runtime' => false, 'database_pool' => false]], 'cli', true],
]);
