<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Instrumentation\HttpInstrumentation;
use Fledge\Apm\Tests\Fixtures\Integration\ApmCard;
use Fledge\Apm\Tests\Fixtures\Integration\ParallelCards;
use Fledge\Apm\Tracer;
use Fledge\Apm\TracerFactory;
use Fledge\Async\FiberContext;
use Fledge\Fiber\Livewire\FiberLivewireServiceProvider;
use Fledge\Fiber\Livewire\Mechanisms\FiberHandleRequests;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Route;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

const CARDS_TRACE_NO_FIBER_CONTEXT = 'The installed fledge-fiber has no Fledge\Async\FiberContext (wave 9b, unreleased): async() fibers do not inherit the trace context automatically yet.';

/**
 * A sqlite database with one table per card, the array cache, and the
 * `/apm/cards` route: one request that renders five cards in parallel
 * async() fibers, each inside its own `card {n}` span.
 */
function cardsTracePrepareApp(): void
{
    config()->set('database.default', 'apm_integration');
    config()->set('database.connections.apm_integration', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
    config()->set('app.key', str_repeat('k', 32));

    ParallelCards::createTables();
    ParallelCards::$log = [];

    Route::get('/apm/cards', function () {
        $tracer = app(Tracer::class);
        $propagation = app(Propagation::class);
        $wrap = request()->boolean('wrap', true);
        $futures = [];

        foreach (ParallelCards::cards() as $card) {
            $render = fn (): array => $tracer->span("card {$card}", fn (): array => ParallelCards::work($card) + [
                'span' => $tracer->currentSpan()->getContext()->getSpanId(),
            ]);

            $futures[$card] = async($wrap ? $propagation->wrap($render) : $render);
        }

        // await() returns in completion order; keep the card order.
        $cards = await($futures);
        ksort($cards);

        return response()->json(['cards' => $cards]);
    });
}

/**
 * Boot fledge-apm the way the app does (ApmServiceProvider::boot()), with
 * the Tracer exporting into an in-memory exporter.
 *
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function cardsTraceBootApm(): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.options.cache.record_keys', true);

    [$tracer, $exporter, $storage] = memoryTracer();

    app()->instance(FiberContextStorage::class, $storage);
    app()->instance(Tracer::class, $tracer);
    app()->forgetInstance(Propagation::class);

    (new ApmServiceProvider(app()))->boot();

    return [$tracer, $exporter, $storage];
}

function cardsTraceIsApmCallable(mixed $listener): bool
{
    $owner = match (true) {
        $listener instanceof Closure => (function (Closure $closure): string {
            $reflection = new ReflectionFunction($closure);
            $bound = $reflection->getClosureThis();

            return $bound !== null ? $bound::class : ($reflection->getClosureScopeClass()?->getName() ?? '');
        })($listener),
        is_string($listener) => $listener,
        is_array($listener) => is_object($listener[0]) ? $listener[0]::class : (string) $listener[0],
        is_object($listener) => $listener::class,
        default => '',
    };

    return str_starts_with($owner, 'Fledge\\Apm\\') && ! str_starts_with($owner, 'Fledge\\Apm\\Tests\\');
}

/**
 * Events with at least one listener registered by fledge-apm.
 *
 * @return list<string>
 */
function cardsTraceApmListenedEvents(): array
{
    $events = [];

    foreach (app('events')->getRawListeners() as $event => $listeners) {
        foreach ($listeners as $listener) {
            if (cardsTraceIsApmCallable($listener)) {
                $events[] = $event;

                break;
            }
        }
    }

    return $events;
}

function cardsTraceApmTerminatingCallbacks(): int
{
    $callbacks = (new ReflectionProperty(Application::class, 'terminatingCallbacks'))->getValue(app());

    return count(array_filter($callbacks, cardsTraceIsApmCallable(...)));
}

/**
 * @param  list<SpanDataInterface>  $spans
 */
function cardsTraceOne(array $spans, Closure $match, string $what): SpanDataInterface
{
    $found = array_values(array_filter($spans, $match));

    expect($found)->toHaveCount(1, "expected exactly one {$what}");

    return $found[0];
}

/**
 * @param  list<SpanDataInterface>  $spans
 */
function cardsTraceNamed(array $spans, string $name): SpanDataInterface
{
    return cardsTraceOne($spans, fn (SpanDataInterface $span): bool => $span->getName() === $name, "span named '{$name}'");
}

/**
 * @param  list<SpanDataInterface>  $spans
 */
function cardsTraceRoot(array $spans): SpanDataInterface
{
    $root = cardsTraceOne($spans, fn (SpanDataInterface $span): bool => ! $span->getParentContext()->isValid(), 'root span');

    expect($root->getKind())->toBe(SpanKind::KIND_SERVER);

    return $root;
}

/**
 * @param  list<SpanDataInterface>  $spans
 */
function cardsTraceAssertSingleTrace(array $spans): string
{
    $traceIds = array_values(array_unique(array_map(fn (SpanDataInterface $span): string => $span->getTraceId(), $spans)));

    expect($traceIds)->toHaveCount(1);

    return $traceIds[0];
}

/**
 * Every card's DB and cache spans hang under one of that card's own spans,
 * never under a sibling's, and the cards really overlapped in time.
 *
 * A card owns one span (a controller card) or several (a Livewire card's
 * update, hydrate and render spans: Livewire calls the component's render()
 * method before it fires the `render` event, so data loaded in render() lands
 * under the component's update span).
 *
 * @param  list<SpanDataInterface>  $spans
 * @param  array<int, list<SpanDataInterface>>  $cardSpans  the spans each card owns, by card number
 */
function cardsTraceAssertNoBleed(array $spans, array $cardSpans): void
{
    /** @var array<string, int> $ownerOf card number by span id */
    $ownerOf = [];

    foreach ($cardSpans as $card => $owned) {
        foreach ($owned as $span) {
            $ownerOf[$span->getSpanId()] = $card;
        }
    }

    expect($ownerOf)->toHaveCount(count($cardSpans, COUNT_RECURSIVE) - count($cardSpans));

    $starts = [];
    $ends = [];

    foreach ($cardSpans as $card => $owned) {
        $table = ParallelCards::table($card);
        $key = ParallelCards::cacheKey($card);

        $work = [
            cardsTraceNamed($spans, "INSERT {$table}"),
            cardsTraceNamed($spans, "SELECT {$table}"),
            ...array_map(fn (string $operation): SpanDataInterface => cardsTraceOne(
                $spans,
                fn (SpanDataInterface $span): bool => $span->getName() === "cache.{$operation}" && $span->getAttributes()->get('cache.key') === $key,
                "cache.{$operation} span for {$key}",
            ), ['put', 'get']),
        ];

        foreach ($work as $span) {
            $owner = $ownerOf[$span->getParentSpanId()] ?? null;

            expect($owner)->toBe(
                $card,
                "'{$span->getName()}' of card {$card} nests under ".($owner === null ? 'a non-card span' : "card {$owner}"),
            );
        }

        $starts[$card] = min(array_map(fn (SpanDataInterface $span): int => $span->getStartEpochNanos(), $owned));
        $ends[$card] = max(array_map(fn (SpanDataInterface $span): int => $span->getEndEpochNanos(), $owned));
    }

    // Every card started before the first one finished.
    expect(max($starts))->toBeLessThan(min($ends));
}

afterEach(function () {
    if (class_exists(FiberContext::class)) {
        FiberContext::forget(Propagation::NAME);
    }
});

it('maps every instrumentation key to an existing class', function () {
    // A class that does not exist is skipped silently by the provider, so a
    // wrongly resolved name would switch that instrumentation off unnoticed.
    foreach (ApmServiceProvider::INSTRUMENTATIONS as $key => $class) {
        expect(class_exists($class))->toBeTrue("apm.instrumentation.{$key} points at missing class {$class}");
    }
});

it('traces one request with five parallel fiber cards as a single tree without context bleed', function (bool $wrap) {
    if (! $wrap && ! class_exists(FiberContext::class)) {
        $this->markTestSkipped(CARDS_TRACE_NO_FIBER_CONTEXT);
    }

    cardsTracePrepareApp();
    [$tracer, $exporter, $storage] = cardsTraceBootApm();

    // Positive control for the disabled-mode assertions below.
    expect(cardsTraceApmListenedEvents())->toContain(QueryExecuted::class, RequestHandled::class, CacheHit::class)
        ->and(app(HttpKernel::class)->hasMiddleware(HttpInstrumentation::class))->toBeTrue()
        ->and(cardsTraceApmTerminatingCallbacks())->toBeGreaterThan(0);

    $response = $this->getJson('/apm/cards?wrap='.(int) $wrap)->assertOk();

    // The cards interleaved: all five suspended before the first one finished,
    // and they finished in a different order than they started.
    $log = ParallelCards::$log;

    expect($log)->toHaveCount(2 * ParallelCards::COUNT)
        ->and(array_slice($log, 0, ParallelCards::COUNT))->toBe(array_map(fn (int $card): string => "start {$card}", ParallelCards::cards()))
        ->and($log[ParallelCards::COUNT])->toBe('end '.ParallelCards::COUNT)
        ->and(array_column($response->json('cards'), 'cached'))->toBe(ParallelCards::cards());

    // After the request the main context is back at the root.
    expect($storage->depth())->toBe(0)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse();

    // Exported by the terminating callback's flush, not by the test.
    $spans = $exporter->getSpans();

    // 1 request + 5 cards + 5 x (2 queries + cache put + cache get).
    expect($spans)->toHaveCount(1 + 5 * ParallelCards::COUNT);

    cardsTraceAssertSingleTrace($spans);

    $root = cardsTraceRoot($spans);

    expect($root->getName())->toBe('GET /apm/cards');

    $cardSpans = [];

    foreach (ParallelCards::cards() as $card) {
        $cardSpans[$card] = cardsTraceNamed($spans, "card {$card}");

        expect($cardSpans[$card]->getParentSpanId())->toBe($root->getSpanId())
            ->and($response->json("cards.{$card}.span"))->toBe($cardSpans[$card]->getSpanId());
    }

    cardsTraceAssertNoBleed($spans, array_map(fn (SpanDataInterface $span): array => [$span], $cardSpans));
})->with([
    'Propagation::wrap()' => [true],
    'automatic FiberContext inheritance' => [false],
]);

it('starts a fresh trace for the next request with no leftovers from the previous one', function () {
    cardsTracePrepareApp();
    [$tracer, $exporter, $storage] = cardsTraceBootApm();

    $this->getJson('/apm/cards')->assertOk();

    $first = cardsTraceAssertSingleTrace($exporter->getSpans());
    $exporter->getStorage()->exchangeArray([]);
    ParallelCards::$log = [];

    $this->getJson('/apm/cards')->assertOk();

    $spans = $exporter->getSpans();

    expect($spans)->toHaveCount(1 + 5 * ParallelCards::COUNT)
        ->and(cardsTraceAssertSingleTrace($spans))->not->toBe($first);

    $root = cardsTraceRoot($spans);
    $cardSpans = [];

    foreach (ParallelCards::cards() as $card) {
        $cardSpans[$card] = cardsTraceNamed($spans, "card {$card}");

        expect($cardSpans[$card]->getParentSpanId())->toBe($root->getSpanId());
    }

    cardsTraceAssertNoBleed($spans, array_map(fn (SpanDataInterface $span): array => [$span], $cardSpans));

    expect($storage->depth())->toBe(0)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse();
});

it('nests every Livewire card of a FiberHandleRequests batch under the update span without context bleed', function () {
    if (! class_exists(FiberContext::class)) {
        $this->markTestSkipped(CARDS_TRACE_NO_FIBER_CONTEXT);
    }

    cardsTracePrepareApp();

    // FiberHandleRequests must be bound before Livewire boots its mechanisms.
    app()->register(FiberLivewireServiceProvider::class);
    app()->register(LivewireServiceProvider::class);
    app('livewire')->component('apm-card', ApmCard::class);

    expect(app(HandleRequests::class))->toBeInstanceOf(FiberHandleRequests::class);

    // Mount the cards before tracing starts, like the page load before the lazy batch.
    $components = [];

    foreach (ParallelCards::cards() as $card) {
        preg_match('/wire:snapshot="([^"]*)"/', app('livewire')->mount('apm-card', ['card' => $card]), $matches);

        $snapshot = html_entity_decode($matches[1]);
        $components[$card] = [
            'snapshot' => $snapshot,
            'id' => json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['id'],
        ];
    }

    [$tracer, $exporter, $storage] = cardsTraceBootApm();
    ParallelCards::$log = [];

    $this->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => array_values(array_map(fn (array $component): array => [
            'snapshot' => $component['snapshot'],
            'updates' => [],
            'calls' => [],
        ], $components)),
    ], ['X-Livewire' => 'true'])->assertOk();

    expect($storage->depth())->toBe(0)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse()
        ->and(array_slice(ParallelCards::$log, 0, ParallelCards::COUNT))
        ->toBe(array_map(fn (int $card): string => "start {$card}", ParallelCards::cards()));

    $spans = $exporter->getSpans();

    cardsTraceAssertSingleTrace($spans);

    $root = cardsTraceRoot($spans);
    $batch = cardsTraceNamed($spans, 'livewire update');

    expect($batch->getParentSpanId())->toBe($root->getSpanId())
        ->and($batch->getAttributes()->get('livewire.batch.size'))->toBe(ParallelCards::COUNT);

    $owned = [];

    foreach ($components as $card => $component) {
        $ofCard = fn (string $phase): Closure => fn (SpanDataInterface $span): bool => $span->getName() === "livewire apm-card {$phase}"
            && $span->getAttributes()->get('livewire.id') === $component['id'];

        $update = cardsTraceOne($spans, $ofCard('update'), "update span of card {$card}");
        $hydrate = cardsTraceOne($spans, $ofCard('hydrate'), "hydrate span of card {$card}");
        $render = cardsTraceOne($spans, $ofCard('render'), "render span of card {$card}");
        $owned[$card] = [$update, $hydrate, $render];

        // Each card's update umbrella hangs under the batch; its phases under that umbrella.
        expect($update->getParentSpanId())->toBe($batch->getSpanId())
            ->and($hydrate->getParentSpanId())->toBe($update->getSpanId())
            ->and($render->getParentSpanId())->toBe($update->getSpanId());
    }

    // 1 request + 1 batch + 5 x (update + hydrate + render + 2 queries + cache put + cache get).
    expect($spans)->toHaveCount(2 + 7 * ParallelCards::COUNT);

    cardsTraceAssertNoBleed($spans, $owned);
});

it('registers nothing and exports nothing when disabled', function () {
    cardsTracePrepareApp();

    // Same wiring as ApmServiceProvider::register(), but with an exporter the
    // test can inspect: if anything traced, it would end up there.
    $exporter = new InMemoryExporter;

    app()->singleton(Tracer::class, fn ($app): Tracer => TracerFactory::make(
        (array) $app['config']->get('apm', []),
        $app->make(FiberContextStorage::class),
        $exporter,
    ));

    if (class_exists(FiberContext::class)) {
        FiberContext::forget(Propagation::NAME);
    }

    config()->set('apm.enabled', false);
    (new ApmServiceProvider(app()))->boot();

    $assertNothingRegistered = function (): void {
        expect(cardsTraceApmListenedEvents())->toBe([])
            ->and(cardsTraceApmTerminatingCallbacks())->toBe(0)
            ->and(app()->bound(HttpInstrumentation::class))->toBeFalse()
            ->and(app(HttpKernel::class)->hasMiddleware(HttpInstrumentation::class))->toBeFalse();

        if (class_exists(FiberContext::class)) {
            expect((new ReflectionProperty(FiberContext::class, 'hooks'))->getValue())->not->toHaveKey(Propagation::NAME);
        }
    };

    // Booting did no tracer work at all.
    expect(app()->resolved(Tracer::class))->toBeFalse()
        ->and(app()->resolved(Propagation::class))->toBeFalse();

    $assertNothingRegistered();

    $response = $this->getJson('/apm/cards')->assertOk();

    // The cards ran; the spans the app opened itself were no-ops.
    $tracer = app(Tracer::class);

    expect(array_column($response->json('cards'), 'cached'))->toBe(ParallelCards::cards())
        ->and(array_unique(array_column($response->json('cards'), 'span')))->toBe(['0000000000000000'])
        ->and($tracer->enabled())->toBeFalse()
        ->and($tracer->flush())->toBeTrue()
        ->and($exporter->getSpans())->toBe([])
        ->and(app(FiberContextStorage::class)->depth())->toBe(0);

    $assertNothingRegistered();
});
