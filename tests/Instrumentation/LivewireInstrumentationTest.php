<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Instrumentation\ExceptionInstrumentation;
use Fledge\Apm\Instrumentation\LivewireInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Component;
use Livewire\LivewireServiceProvider;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;
use function Livewire\trigger;

abstract class ApmProbe extends Component
{
    public int $count = 0;

    public function bump(): void
    {
        app(Tracer::class)->span('call query '.static::LABEL, fn () => delay(static::DELAY), SpanKind::KIND_CLIENT);

        $this->count++;
    }

    public function explode(): void
    {
        throw new RuntimeException('probe exploded');
    }

    public function render(): string
    {
        delay(static::DELAY);

        return '<div>{{ $count }}</div>';
    }
}

class ApmProbeA extends ApmProbe
{
    public const string LABEL = 'a';

    public const float DELAY = 0.04;
}

class ApmProbeB extends ApmProbe
{
    public const string LABEL = 'b';

    public const float DELAY = 0.01;
}

/**
 * Does its "query" in render() itself (which Livewire calls before the
 * `render` event) and in an updated hook.
 */
class ApmRenderProbe extends Component
{
    public int $count = 0;

    public string $label = '';

    public function updatedLabel(): void
    {
        app(Tracer::class)->span('updated query', fn () => null, SpanKind::KIND_CLIENT);
    }

    public function render(): string
    {
        app(Tracer::class)->span('render query', fn () => null, SpanKind::KIND_CLIENT);

        return '<div>{{ $count }}</div>';
    }
}

/**
 * Livewire registered, the Tracer bound for the probe components, and the
 * instrumentation hooked into an in-memory tracer.
 *
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function livewireTracer(?LivewireInstrumentation $instrumentation = null): array
{
    // Livewire signs snapshots with the application key.
    config()->set('app.key', str_repeat('k', 32));

    app()->register(LivewireServiceProvider::class);

    [$tracer, $exporter, $storage] = memoryTracer();

    app()->instance(Tracer::class, $tracer);
    app()->instance('apm.test.exporter', $exporter);
    app('livewire')->component('apm-probe-a', ApmProbeA::class);
    app('livewire')->component('apm-probe-b', ApmProbeB::class);

    ($instrumentation ?? new LivewireInstrumentation)->register(app(), $tracer);

    return [$tracer, $exporter, $storage];
}

/**
 * The snapshot of a really mounted probe component, ready for update(). The
 * spans of that setup mount are discarded from the exporter.
 *
 * @return array<string, mixed>
 */
function probeSnapshot(string $name): array
{
    $html = app('livewire')->mount($name);

    preg_match('/wire:snapshot="([^"]*)"/', $html, $matches);

    $tracer = app(Tracer::class);
    $tracer->flush();

    if (app()->bound('apm.test.exporter')) {
        app('apm.test.exporter')->getStorage()->exchangeArray([]);
    }

    return json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, SpanDataInterface>
 */
function livewireSpansByName(Tracer $tracer, InMemoryExporter $exporter): array
{
    $tracer->flush();

    return collect($exporter->getSpans())
        ->keyBy(fn (SpanDataInterface $span) => $span->getName())
        ->all();
}

it('records mount and render spans nested under the span active when the component mounts', function () {
    [$tracer, $exporter, $storage] = livewireTracer();

    $root = $tracer->startSpan('GET /page', SpanKind::KIND_SERVER);
    $rootId = $root->span->getContext()->getSpanId();

    $html = app('livewire')->mount('apm-probe-a');

    $root->end();

    $spans = livewireSpansByName($tracer, $exporter);
    $mount = $spans['livewire apm-probe-a mount'];

    expect($html)->toContain('wire:snapshot')
        ->and($mount->getParentSpanId())->toBe($rootId)
        ->and($mount->getKind())->toBe(SpanKind::KIND_INTERNAL)
        ->and($mount->getAttributes()->get('livewire.component'))->toBe('apm-probe-a')
        ->and($mount->getAttributes()->get('livewire.phase'))->toBe('mount')
        ->and($mount->getAttributes()->get('livewire.id'))->toBeString()->not->toBeEmpty()
        ->and($spans['livewire apm-probe-a render']->getParentSpanId())->toBe($mount->getSpanId())
        ->and($spans)->not->toHaveKey('livewire apm-probe-a call')
        ->and($storage->depth())->toBe(0);
});

it('records hydrate, call and render spans for an update with the called method as attribute', function () {
    [$tracer, $exporter, $storage] = livewireTracer();
    $snapshot = probeSnapshot('apm-probe-a');

    $root = $tracer->startSpan('POST /livewire/update', SpanKind::KIND_SERVER);
    $rootId = $root->span->getContext()->getSpanId();

    [$newSnapshot] = app('livewire')->update($snapshot, ['count' => 5], [['method' => 'bump', 'params' => []]]);

    $root->end();

    $spans = livewireSpansByName($tracer, $exporter);
    $update = $spans['livewire apm-probe-a update'];
    $hydrate = $spans['livewire apm-probe-a hydrate'];
    $properties = $spans['livewire apm-probe-a properties'];
    $call = $spans['livewire apm-probe-a call'];
    $render = $spans['livewire apm-probe-a render'];

    expect($newSnapshot['data']['count'])->toBe(6)
        ->and($update->getParentSpanId())->toBe($rootId)
        ->and($update->getAttributes()->get('livewire.phase'))->toBe('update')
        ->and($hydrate->getParentSpanId())->toBe($update->getSpanId())
        ->and($properties->getParentSpanId())->toBe($update->getSpanId())
        ->and($call->getParentSpanId())->toBe($update->getSpanId())
        ->and($render->getParentSpanId())->toBe($update->getSpanId())
        ->and($call->getAttributes()->get('livewire.method'))->toBe('bump')
        ->and($call->getAttributes()->get('livewire.component'))->toBe('apm-probe-a')
        ->and($spans['call query a']->getParentSpanId())->toBe($call->getSpanId())
        // the phases run in order, inside the update umbrella
        ->and($hydrate->getEndEpochNanos())->toBeLessThanOrEqual($properties->getStartEpochNanos())
        ->and($properties->getEndEpochNanos())->toBeLessThanOrEqual($call->getStartEpochNanos())
        ->and($call->getEndEpochNanos())->toBeLessThanOrEqual($render->getStartEpochNanos())
        ->and($update->getStartEpochNanos())->toBeLessThanOrEqual($hydrate->getStartEpochNanos())
        ->and($update->getEndEpochNanos())->toBeGreaterThanOrEqual($render->getEndEpochNanos())
        ->and($storage->depth())->toBe(0);
});

it('nests render-time work under the component update span, not under hydrate', function (array $updates) {
    [$tracer, $exporter, $storage] = livewireTracer();
    app('livewire')->component('apm-render-probe', ApmRenderProbe::class);
    $snapshot = probeSnapshot('apm-render-probe');

    $root = $tracer->startSpan('POST /livewire/update', SpanKind::KIND_SERVER);

    // No calls: Livewire runs render() right after hydrating, before the
    // `render` event, and fires nothing in between.
    app('livewire')->update($snapshot, $updates, []);

    $root->end();

    $spans = livewireSpansByName($tracer, $exporter);
    $update = $spans['livewire apm-render-probe update'];
    $hydrate = $spans['livewire apm-render-probe hydrate'];
    $query = $spans['render query'];

    expect($update->getParentSpanId())->toBe($root->span->getContext()->getSpanId())
        ->and($query->getParentSpanId())->toBe($update->getSpanId())
        ->and($hydrate->getParentSpanId())->toBe($update->getSpanId())
        ->and($hydrate->getEndEpochNanos())->toBeLessThanOrEqual($query->getStartEpochNanos())
        ->and($storage->depth())->toBe(0);
})->with([
    'without updates' => [[]],
    'with a property update' => [['count' => 3]],
]);

it('nests the updated hooks under the properties span', function () {
    [$tracer, $exporter, $storage] = livewireTracer();
    app('livewire')->component('apm-render-probe', ApmRenderProbe::class);
    $snapshot = probeSnapshot('apm-render-probe');

    $root = $tracer->startSpan('POST /livewire/update', SpanKind::KIND_SERVER);

    // Two updates: the updatedLabel hook runs in the second finisher, after
    // the first finisher already ran.
    [$newSnapshot] = app('livewire')->update($snapshot, ['count' => 3, 'label' => 'x'], []);

    $root->end();

    $spans = livewireSpansByName($tracer, $exporter);
    $properties = $spans['livewire apm-render-probe properties'];

    expect($newSnapshot['data']['count'])->toBe(3)
        ->and(collect($exporter->getSpans())->filter(fn (SpanDataInterface $span) => $span->getName() === 'livewire apm-render-probe properties')->all())->toHaveCount(1)
        ->and($properties->getParentSpanId())->toBe($spans['livewire apm-render-probe update']->getSpanId())
        ->and($spans['updated query']->getParentSpanId())->toBe($properties->getSpanId())
        ->and($properties->getEndEpochNanos())->toBeLessThanOrEqual($spans['render query']->getStartEpochNanos())
        ->and($storage->depth())->toBe(0);
});

it('records a component exception once when the exception handler reports it too', function () {
    config()->set('logging.default', 'null');

    [$tracer, $exporter] = livewireTracer();
    (new ExceptionInstrumentation)->register(app(), $tracer);
    $snapshot = probeSnapshot('apm-probe-a');

    $root = $tracer->startSpan('POST /livewire/update', SpanKind::KIND_SERVER);

    try {
        app('livewire')->update($snapshot, [], [['method' => 'explode', 'params' => []]]);
    } catch (RuntimeException $exception) {
        // The kernel's handler reports what escaped the component.
        report($exception);
    }

    event(new RequestHandled(Request::create('/livewire/update'), new Response));
    $root->end();

    $spans = livewireSpansByName($tracer, $exporter);
    $events = collect($spans)->flatMap(fn (SpanDataInterface $span) => $span->getEvents())->values();

    expect($events)->toHaveCount(1)
        ->and($spans['livewire apm-probe-a call']->getEvents())->toHaveCount(1)
        ->and($spans['livewire apm-probe-a call']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans['POST /livewire/update']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
});

it('parents each parallel component span to its own fiber and component, under the batch span', function () {
    [$tracer, $exporter, $storage] = livewireTracer();
    $propagation = new Propagation($storage);

    $snapshots = ['a' => probeSnapshot('apm-probe-a'), 'b' => probeSnapshot('apm-probe-b')];

    $root = $tracer->startSpan('POST /livewire/update', SpanKind::KIND_SERVER);
    $rootId = $root->span->getContext()->getSpanId();

    // FiberHandleRequests triggers 'request' before spawning the component
    // fibers and 'response' after awaiting them.
    trigger('request', $snapshots);

    $futures = [];

    foreach ($snapshots as $label => $snapshot) {
        // wrap() runs here in the parent, as the async() context hook would.
        $futures[$label] = async($propagation->wrap(
            fn () => app('livewire')->update($snapshot, [], [['method' => 'bump', 'params' => []]])
        ));
    }

    $results = await($futures);

    trigger('response', ['components' => []]);

    $root->end();

    expect($results['a'][0]['data']['count'])->toBe(1)
        ->and($results['b'][0]['data']['count'])->toBe(1)
        ->and($storage->depth())->toBe(0);

    $spans = livewireSpansByName($tracer, $exporter);
    $batch = $spans['livewire update'];

    expect($batch->getParentSpanId())->toBe($rootId)
        ->and($batch->getAttributes()->get('livewire.batch.size'))->toBe(2);

    foreach (['a', 'b'] as $label) {
        $name = "apm-probe-{$label}";
        $call = $spans["livewire {$name} call"];
        $update = $spans["livewire {$name} update"];

        expect($update->getParentSpanId())->toBe($batch->getSpanId())
            ->and($spans["livewire {$name} hydrate"]->getParentSpanId())->toBe($update->getSpanId())
            ->and($call->getParentSpanId())->toBe($update->getSpanId())
            ->and($spans["livewire {$name} render"]->getParentSpanId())->toBe($update->getSpanId())
            // the query ran while the other component's fiber was suspended,
            // and still landed under its own component's call span
            ->and($spans["call query {$label}"]->getParentSpanId())->toBe($call->getSpanId())
            ->and($call->getTraceId())->toBe($batch->getTraceId());
    }

    // The fibers really interleaved: the quick component finished its call
    // while the slow one was still inside its own.
    $callA = $spans['livewire apm-probe-a call'];
    $callB = $spans['livewire apm-probe-b call'];

    expect($callB->getStartEpochNanos())->toBeGreaterThan($callA->getStartEpochNanos())
        ->and($callB->getEndEpochNanos())->toBeLessThan($callA->getEndEpochNanos())
        ->and($spans['livewire apm-probe-a call']->getAttributes()->get('livewire.id'))
        ->not->toBe($spans['livewire apm-probe-b call']->getAttributes()->get('livewire.id'));
});

it('records a failing call on the call span and ends it when the request is handled', function () {
    [$tracer, $exporter, $storage] = livewireTracer();
    $snapshot = probeSnapshot('apm-probe-a');

    $root = $tracer->startSpan('POST /livewire/update', SpanKind::KIND_SERVER);

    expect(fn () => app('livewire')->update($snapshot, [], [['method' => 'explode', 'params' => []]]))
        ->toThrow(RuntimeException::class, 'probe exploded');

    // The call finisher never ran; the span is still open until the sweep.
    event(new RequestHandled(Request::create('/livewire/update'), new Response));

    $root->end();

    $spans = livewireSpansByName($tracer, $exporter);
    $call = $spans['livewire apm-probe-a call'];
    $events = $call->getEvents();

    expect($call->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->getName())->toBe('exception')
        ->and($events[0]->getAttributes()->get('exception.type'))->toBe(RuntimeException::class)
        ->and($events[0]->getAttributes()->get('exception.message'))->toBe('probe exploded')
        ->and($spans['livewire apm-probe-a hydrate']->getStatus()->getCode())->not->toBe(StatusCode::STATUS_ERROR)
        ->and($spans['livewire apm-probe-a update']->getStatus()->getCode())->not->toBe(StatusCode::STATUS_ERROR)
        ->and($spans['livewire apm-probe-a update']->getEvents())->toBeEmpty()
        ->and($spans)->not->toHaveKey('livewire apm-probe-a render')
        ->and($storage->depth())->toBe(0);
});

it('is a no-op when Livewire is not available', function () {
    $absent = new class extends LivewireInstrumentation
    {
        protected function livewireAvailable(): bool
        {
            return false;
        }
    };

    [$tracer, $exporter] = livewireTracer($absent);
    $snapshot = probeSnapshot('apm-probe-a');

    app('livewire')->update($snapshot, [], [['method' => 'bump', 'params' => []]]);

    // Only the probe's own query span exists; no component span was recorded.
    expect(array_keys(livewireSpansByName($tracer, $exporter)))->toBe(['call query a']);
});

it('registers no listeners for a disabled tracer', function () {
    config()->set('app.key', str_repeat('k', 32));

    app()->register(LivewireServiceProvider::class);
    app('livewire')->component('apm-probe-a', ApmProbeA::class);
    app()->instance(Tracer::class, Tracer::disabled());

    (new LivewireInstrumentation)->register(app(), Tracer::disabled());

    [$newSnapshot] = app('livewire')->update(
        probeSnapshot('apm-probe-a'), [], [['method' => 'bump', 'params' => []]]
    );

    expect($newSnapshot['data']['count'])->toBe(1);
});
