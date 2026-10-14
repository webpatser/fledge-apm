<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Closure;
use Fiber;
use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Validation\ValidationException;
use Livewire\EventBus;
use Throwable;
use WeakMap;

/**
 * An INTERNAL span per Livewire component phase, from Livewire's own
 * lifecycle events (no FiberHandleRequests hook needed):
 *
 * - `livewire {name} mount`   from `mount` to its finisher (covers render and dehydrate)
 * - `livewire {name} update`  per component update, from `hydrate` to `destroy`
 *   (after the snapshot); everything below nests under it, and so does
 *   the component's render() method, which Livewire runs before the
 *   `render` event
 *   - `livewire {name} hydrate`    the `hydrate` listeners (boot and hydrate
 *     hooks); the snapshot hydration just before the event is not included
 *   - `livewire {name} properties` the property updates and their
 *     updating/updated hooks, only when the request carries updates
 *   - `livewire {name} call`       one per called method, `livewire.method` attribute
 *   - `livewire {name} render`     from `render` to its finisher (the Blade render)
 * - `livewire update`         one per update request, from the `request`
 *   event to the `response` event, so every component of a
 *   FiberHandleRequests batch is a descendant of it
 *
 * Fiber-correct by construction: the open spans live in a WeakMap keyed by
 * the component instance (each parallel fiber works on its own instance),
 * and a span is parented to whatever span is current in the fiber that
 * starts it. There is no shared "current component" state.
 *
 * Spans are ended by their Livewire finisher (or the event that follows).
 * When a component throws, the finisher never runs: the exception is recorded
 * on the innermost open span (once, see ExceptionRecorder), and the leftovers
 * are ended when the component is garbage collected (a fiber that died with
 * it) or at the latest when the request is handled.
 *
 * A no-op when Livewire is not installed.
 */
class LivewireInstrumentation implements Instrumentation
{
    /** @var WeakMap<object, LivewireComponentSpans> */
    private WeakMap $components;

    /** @var WeakMap<object, ActiveSpan> update-request span by fiber (or the main sentinel) */
    private WeakMap $batches;

    private object $mainKey;

    private Tracer $tracer;

    public function register(Application $app, Tracer $tracer): void
    {
        if (! $tracer->enabled() || ! $this->livewireAvailable()) {
            return;
        }

        $this->tracer = $tracer;
        $this->components = new WeakMap;
        $this->batches = new WeakMap;
        $this->mainKey = new \stdClass;

        // Livewire's event bus only exists once its provider ran, so wait for
        // every provider to boot.
        $app->booted(function () use ($app): void {
            $this->listen($app->make(EventBus::class));

            $app->make('events')->listen(RequestHandled::class, $this->sweep(...));
        });
    }

    protected function livewireAvailable(): bool
    {
        return class_exists(EventBus::class);
    }

    private function listen(EventBus $bus): void
    {
        // before(): runs ahead of Livewire's own listeners, so the component's
        // mount() / render() / method execute inside the span.
        $bus->before('mount', function (object $component): Closure {
            $span = $this->open($component, 'mount');

            return fn () => $this->close($component, $span);
        });

        // The update umbrella opens first, so hydrate, the property updates,
        // calls, render() (which Livewire runs before the `render` event) and
        // dehydrate all nest under the component.
        $bus->before('hydrate', function (object $component): void {
            $this->open($component, 'update');
            $this->open($component, 'hydrate');
        });

        // after(): runs once every hydrate listener (the component's boot and
        // hydrate hooks) ran. Ending hydrate here, not at the next phase, is
        // what keeps render() and the property updates out of it: Livewire
        // fires nothing between an update without calls and render().
        $bus->after('hydrate', function (object $component): void {
            $this->closePhase($component, 'hydrate');
        });

        // One `properties` span from the first property update (before its
        // updating hooks) to the finisher of the last one (after its updated
        // hooks: after() finishers run last). Livewire applies every update
        // before it runs any finisher, so a counter finds the last one.
        $bus->before('update', function (object $component): void {
            $spans = $this->components[$component] ?? null;

            if ($spans === null || ! $spans->has('update')) {
                return;
            }

            if ($spans->pendingUpdates++ === 0) {
                $this->open($component, 'properties');
            }
        });

        $bus->after('update', function (object $component): ?Closure {
            $spans = $this->components[$component] ?? null;

            if ($spans === null || ! $spans->has('properties')) {
                return null;
            }

            return function () use ($component, $spans): void {
                if (--$spans->pendingUpdates === 0) {
                    $this->closePhase($component, 'properties');
                }
            };
        });

        $bus->before('call', function (object $component, string $method): Closure {
            $this->closePhase($component, 'hydrate');
            $this->closePhase($component, 'properties');

            $span = $this->open($component, 'call', ['livewire.method' => $method]);

            return fn () => $this->close($component, $span);
        });

        $bus->before('render', function (object $component): Closure {
            $this->closePhase($component, 'hydrate');
            $this->closePhase($component, 'properties');

            $span = $this->open($component, 'render');

            return fn () => $this->close($component, $span);
        });

        $bus->before('dehydrate', function (object $component): void {
            $this->closePhase($component, 'hydrate');
            $this->closePhase($component, 'properties');
        });

        // `destroy` fires after the snapshot was taken: the update is done.
        // Mount fires it too, but its span is ended by the mount finisher.
        $bus->after('destroy', function (object $component): void {
            $spans = $this->components[$component] ?? null;

            if ($spans !== null && $spans->has('update')) {
                $spans->endAll();
            }
        });

        $bus->on('exception', function (object $component, Throwable $exception): void {
            // Validation failures are normal control flow, not errors.
            if ($exception instanceof ValidationException) {
                return;
            }

            $innermost = isset($this->components[$component]) ? $this->components[$component]->innermost() : null;

            // The exception handler reports it again later: recorded once.
            if ($innermost !== null) {
                ExceptionRecorder::record($innermost->span, $exception);
            }
        });

        $bus->before('request', function (mixed $payload): void {
            $key = Fiber::getCurrent() ?? $this->mainKey;

            // A batch that never reached its response (an exception) is stale.
            if (isset($this->batches[$key])) {
                $this->batches[$key]->end();
            }

            $this->batches[$key] = $this->tracer->startSpan('livewire update', attributes: [
                'livewire.phase' => 'update',
                'livewire.batch.size' => is_countable($payload) ? count($payload) : 0,
            ]);
        });

        $bus->on('response', function (): void {
            $key = Fiber::getCurrent() ?? $this->mainKey;

            if (isset($this->batches[$key])) {
                $this->batches[$key]->end();
                unset($this->batches[$key]);
            }
        });
    }

    /**
     * @param  array<non-empty-string, mixed>  $attributes
     */
    private function open(object $component, string $phase, array $attributes = []): ActiveSpan
    {
        $name = $this->componentName($component);

        $span = $this->tracer->startSpan("livewire {$name} {$phase}", attributes: [
            'livewire.component' => $name,
            'livewire.id' => method_exists($component, 'getId') ? (string) $component->getId() : '',
            'livewire.phase' => $phase,
            ...$attributes,
        ]);

        if (! isset($this->components[$component])) {
            $this->components[$component] = new LivewireComponentSpans;
        }

        $this->components[$component]->push($phase, $span);

        return $span;
    }

    private function close(object $component, ActiveSpan $span): void
    {
        if (isset($this->components[$component])) {
            $this->components[$component]->forget($span);
        }

        $span->end();
    }

    private function closePhase(object $component, string $phase): void
    {
        if (isset($this->components[$component])) {
            $this->components[$component]->endPhase($phase);
        }
    }

    private function componentName(object $component): string
    {
        $name = method_exists($component, 'getName') ? $component->getName() : null;

        return is_string($name) && $name !== '' ? $name : $component::class;
    }

    /**
     * End whatever a failed component and a failed update request left open.
     */
    private function sweep(): void
    {
        foreach ($this->components as $spans) {
            $spans->endAll();
        }

        foreach ($this->batches as $batch) {
            $batch->end();
        }

        $this->batches = new WeakMap;
    }
}

/**
 * The spans one component instance has open, innermost last.
 *
 * @internal
 */
final class LivewireComponentSpans
{
    /** @var array<int, array{string, ActiveSpan}> by spl_object_id of the ActiveSpan */
    private array $open = [];

    /** Property updates whose finisher has not run yet. */
    public int $pendingUpdates = 0;

    public function __destruct()
    {
        $this->endAll();
    }

    public function push(string $phase, ActiveSpan $span): void
    {
        $this->open[spl_object_id($span)] = [$phase, $span];
    }

    public function forget(ActiveSpan $span): void
    {
        unset($this->open[spl_object_id($span)]);
    }

    public function has(string $phase): bool
    {
        return array_any($this->open, static fn (array $entry): bool => $entry[0] === $phase);
    }

    public function innermost(): ?ActiveSpan
    {
        return $this->open === [] ? null : end($this->open)[1];
    }

    public function endPhase(string $phase): void
    {
        foreach ($this->open as $id => [$openPhase, $span]) {
            if ($openPhase === $phase) {
                unset($this->open[$id]);
                $span->end();
            }
        }
    }

    public function endAll(): void
    {
        while ($this->open !== []) {
            [, $span] = array_pop($this->open);
            $span->end();
        }
    }
}
