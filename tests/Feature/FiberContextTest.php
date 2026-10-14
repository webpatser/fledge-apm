<?php

declare(strict_types=1);

use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Context\Propagation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\FiberBoundContextStorage;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

it('gives five parallel async fibers sibling spans under one parent without context bleed', function () {
    [$tracer, $exporter, $storage] = memoryTracer();
    $propagation = new Propagation($storage);

    $parent = $tracer->startSpan('GET /pulse', SpanKind::KIND_SERVER);
    $parentId = $parent->span->getContext()->getSpanId();
    $traceId = $parent->span->getContext()->getTraceId();
    $log = [];

    $futures = [];

    foreach (range(1, 5) as $i) {
        // wrap() runs here, in the parent, exactly like the planned async() hook.
        $futures[$i] = async($propagation->wrap(function () use ($tracer, $i, &$log): array {
            $inherited = $tracer->currentSpan()->getContext()->getSpanId();

            $result = $tracer->span("card {$i}", function (ActiveSpan $span) use ($tracer, $i, &$log): array {
                $own = $span->span->getContext()->getSpanId();
                $log[] = "start {$i}";

                // Suspend while the span is current: the other cards run meanwhile.
                delay(0.002 * (6 - $i));

                $afterSuspend = $tracer->currentSpan()->getContext()->getSpanId();

                $tracer->span("query {$i}", fn () => delay(0.001), SpanKind::KIND_CLIENT);

                $afterNested = $tracer->currentSpan()->getContext()->getSpanId();
                $log[] = "end {$i}";

                return ['own' => $own, 'afterSuspend' => $afterSuspend, 'afterNested' => $afterNested];
            });

            return $result + [
                'inherited' => $inherited,
                'afterSpan' => $tracer->currentSpan()->getContext()->getSpanId(),
            ];
        }));
    }

    $results = await($futures);

    // The cards really interleaved: every card started before the first one ended.
    expect(array_search('start 5', $log, true))->toBeLessThan(array_search('end 1', $log, true));

    // The parent's own context is untouched by the five fibers.
    expect($tracer->currentSpan()->getContext()->getSpanId())->toBe($parentId);

    $parent->end();

    expect($tracer->currentSpan()->getContext()->isValid())->toBeFalse()
        ->and($storage->depth())->toBe(0);

    expect($tracer->flush())->toBeTrue();

    /** @var array<string, SpanDataInterface> $spans */
    $spans = collect($exporter->getSpans())->keyBy(fn (SpanDataInterface $span) => $span->getName())->all();

    expect($spans)->toHaveCount(11);

    $cardIds = [];

    foreach (range(1, 5) as $i) {
        $result = $results[$i];
        $card = $spans["card {$i}"];
        $query = $spans["query {$i}"];
        $cardIds[] = $card->getSpanId();

        expect($result['inherited'])->toBe($parentId)
            ->and($result['own'])->toBe($card->getSpanId())
            ->and($result['afterSuspend'])->toBe($result['own'])
            ->and($result['afterNested'])->toBe($result['own'])
            ->and($result['afterSpan'])->toBe($parentId)
            ->and($card->getParentSpanId())->toBe($parentId)
            ->and($card->getTraceId())->toBe($traceId)
            ->and($query->getParentSpanId())->toBe($card->getSpanId())
            ->and($query->getTraceId())->toBe($traceId);
    }

    expect(array_unique($cardIds))->toHaveCount(5)
        ->and($spans['GET /pulse']->getParentContext()->isValid())->toBeFalse();
});

it('starts a reused Revolt callback fiber with an empty stack after a callback leaked a scope', function () {
    [$tracer, , $storage] = memoryTracer();
    $fibers = [];

    $leaky = async(function () use ($tracer, &$fibers): bool {
        $fibers[] = Fiber::getCurrent();
        $tracer->startSpan('leaked'); // never ended: its scope leaks

        return $tracer->currentSpan()->getContext()->isValid();
    });

    $next = async(function () use ($tracer, $storage, &$fibers): array {
        $fibers[] = Fiber::getCurrent();

        return [$tracer->currentSpan()->getContext()->isValid(), $storage->depth()];
    });

    [$leakedActive, [$nextSeesSpan, $nextDepth]] = await([$leaky, $next]);

    expect($fibers)->toHaveCount(2)
        ->and($fibers[0])->toBe($fibers[1]) // the same callback fiber ran both
        ->and($leakedActive)->toBeTrue()
        ->and($nextSeesSpan)->toBeFalse()
        ->and($nextDepth)->toBe(0);
});

it('shows the SDK FiberBoundContextStorage leaking into a reused fiber (control)', function () {
    $stock = new FiberBoundContextStorage;
    $fibers = [];
    $marker = Context::getRoot()->with(Context::createKey('marker'), 'leaked');

    $leaky = async(function () use ($stock, $marker, &$fibers): void {
        $fibers[] = Fiber::getCurrent();
        $stock->attach($marker); // never detached
    });

    $next = async(function () use ($stock, &$fibers): bool {
        $fibers[] = Fiber::getCurrent();

        return $stock->current() !== Context::getRoot();
    });

    [, $bled] = await([$leaky, $next]);

    expect($fibers[0])->toBe($fibers[1])
        ->and($bled)->toBeTrue();
});

it('starts an async fiber nobody restored at the root context, without warnings', function () {
    [$tracer] = memoryTracer();

    $parent = $tracer->startSpan('parent');

    $childSeesParent = async(fn (): bool => $tracer->currentSpan()->getContext()->isValid())->await();

    $parent->end();

    expect($childSeesParent)->toBeFalse();
});

it('drops scopes a child leaked when its restored base is released', function () {
    [$tracer, , $storage] = memoryTracer();

    $parent = $tracer->startSpan('parent');
    $snapshot = $storage->capture();
    $parent->end();

    $base = $storage->restore($snapshot);
    $leakedOne = $tracer->startSpan('leaked one');
    $leakedTwo = $tracer->startSpan('leaked two');

    expect($storage->depth())->toBe(3);

    $storage->release($base);

    expect($storage->depth())->toBe(0)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse()
        ->and($base->detach() & ScopeInterface::DETACHED)->not->toBe(0);

    // Ending the leaked spans later is harmless.
    $leakedTwo->end();
    $leakedOne->end();

    expect($storage->depth())->toBe(0);
});

it('clears scopes leaked on the main context without breaking a span still in flight', function () {
    [$tracer, $exporter, $storage] = memoryTracer();

    $job = $tracer->startSpan('job');
    $tracer->startSpan('leaked child'); // never ended

    expect($storage->depth())->toBe(2)
        ->and($storage->reset())->toBe(2)
        ->and($storage->depth())->toBe(0)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse();

    // The next unit of work starts a fresh trace, not under the leaked child.
    $next = $tracer->startSpan('next job');
    $next->end();

    // The span that was in flight still ends and exports normally.
    $job->end();

    expect($storage->depth())->toBe(0);

    $tracer->flush();

    /** @var array<string, SpanDataInterface> $spans */
    $spans = collect($exporter->getSpans())->keyBy(fn (SpanDataInterface $span) => $span->getName())->all();

    expect(array_keys($spans))->toEqualCanonicalizing(['job', 'next job'])
        ->and($spans['next job']->getParentContext()->isValid())->toBeFalse()
        ->and($spans['next job']->getTraceId())->not->toBe($spans['job']->getTraceId());
});

it('leaves fiber stacks alone on reset', function () {
    [$tracer, , $storage] = memoryTracer();

    $fiber = async(function () use ($tracer): bool {
        $span = $tracer->startSpan('fiber span');
        delay(0.01);

        $stillCurrent = $tracer->currentSpan()->getContext()->getSpanId() === $span->span->getContext()->getSpanId();
        $span->end();

        return $stillCurrent;
    });

    delay(0.001); // the fiber is suspended with its span current

    $storage->reset();

    expect($fiber->await())->toBeTrue();
});

it('captures nothing at the root context', function () {
    [, , $storage] = memoryTracer();

    expect($storage->capture())->toBeNull()
        ->and((new Propagation($storage))->restore(null))->toBeNull();
});

it('registers with the fledge-fiber async() hook only when it exists', function () {
    [, , $storage] = memoryTracer();

    expect((new Propagation($storage))->register())->toBe(class_exists('Fledge\\Async\\FiberContext'));
});
