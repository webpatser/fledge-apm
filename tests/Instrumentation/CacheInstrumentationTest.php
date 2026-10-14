<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Instrumentation\CacheInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Support\Facades\Cache;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function cacheTracer(): array
{
    $result = memoryTracer();

    (new CacheInstrumentation)->register(app(), $result[0]);

    return $result;
}

/**
 * @return list<SpanDataInterface>
 */
function cacheSpans(Tracer $tracer, InMemoryExporter $exporter): array
{
    $tracer->flush();

    return array_values($exporter->getSpans());
}

it('records get, put and forget spans with hit, store and ttl', function () {
    [$tracer, $exporter] = cacheTracer();

    $parent = $tracer->startSpan('GET /');
    Cache::put('profile:1', 'value', 60);
    Cache::get('profile:1');
    Cache::get('profile:missing');
    Cache::forget('profile:1');
    $parent->end();

    $spans = collect(cacheSpans($tracer, $exporter))->reject(fn (SpanDataInterface $span) => $span->getName() === 'GET /')->values();

    expect($spans->map->getName()->all())->toBe(['cache.put', 'cache.get', 'cache.get', 'cache.forget']);

    $put = $spans[0]->getAttributes();
    $hit = $spans[1]->getAttributes();
    $miss = $spans[2]->getAttributes();

    expect($put->get('cache.operation'))->toBe('put')
        ->and($put->get('cache.ttl'))->toBe(60)
        ->and($put->get('cache.store'))->toBe(config('cache.default'))
        ->and($hit->get('cache.hit'))->toBeTrue()
        ->and($miss->get('cache.hit'))->toBeFalse()
        ->and($spans->every(fn (SpanDataInterface $span) => $span->getParentSpanId() === $parent->span->getContext()->getSpanId()))->toBeTrue();
});

it('keeps cache keys out of spans unless record_keys is on', function (bool $recordKeys) {
    config(['apm.options.cache.record_keys' => $recordKeys]);

    [$tracer, $exporter] = cacheTracer();

    $parent = $tracer->startSpan('GET /');
    Cache::get('user:secret@example.test');
    $parent->end();

    $span = collect(cacheSpans($tracer, $exporter))->first(fn (SpanDataInterface $span) => $span->getName() === 'cache.get');

    expect($span->getAttributes()->get('cache.key'))->toBe($recordKeys ? 'user:secret@example.test' : null);
})->with([true, false]);

it('times a span from the starting event to the outcome event', function () {
    [$tracer, $exporter] = cacheTracer();

    $parent = $tracer->startSpan('GET /');
    event(new RetrievingKey('array', 'k'));
    usleep(8_000);
    event(new CacheHit('array', 'k', 'v'));
    $parent->end();

    $span = collect(cacheSpans($tracer, $exporter))->first(fn (SpanDataInterface $span) => $span->getName() === 'cache.get');

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())->toBeGreaterThanOrEqual(8_000_000);
});

it('records an outcome without a starting event as a zero-duration span', function () {
    [$tracer, $exporter] = cacheTracer();

    $parent = $tracer->startSpan('GET /');
    event(new CacheMissed('array', 'many-1'));
    $parent->end();

    $span = collect(cacheSpans($tracer, $exporter))->first(fn (SpanDataInterface $span) => $span->getName() === 'cache.get');

    expect($span->getEndEpochNanos() - $span->getStartEpochNanos())->toBe(0);
});

it('marks a failed write as an error', function () {
    [$tracer, $exporter] = cacheTracer();

    $parent = $tracer->startSpan('GET /');
    event(new WritingKey('array', 'k', 'v', 30));
    event(new KeyWriteFailed('array', 'k', 'v', 30));
    $parent->end();

    $span = collect(cacheSpans($tracer, $exporter))->first(fn (SpanDataInterface $span) => $span->getName() === 'cache.put');

    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
});

it('records nothing outside a trace', function () {
    [$tracer, $exporter] = cacheTracer();

    Cache::put('a', 1, 60);
    Cache::get('a');

    expect(cacheSpans($tracer, $exporter))->toBe([]);
});

it('keeps pending operations of parallel fibers apart even for the same key', function () {
    [$tracer, $exporter, $storage] = cacheTracer();
    $propagation = new Propagation($storage);

    $parent = $tracer->startSpan('GET /pulse');

    // Both fibers read the same key. Fiber "slow" starts first and finishes
    // last; fiber "fast" starts while "slow" is pending. One shared slot per
    // key would hand "fast" the start time of "slow" (or the reverse).
    $slow = async($propagation->wrap(function (): void {
        $name = 'slow';
        event(new RetrievingKey('array', 'shared'));
        delay(0.04);
        event(new CacheHit('array', 'shared', $name));
    }));

    $fast = async($propagation->wrap(function (): void {
        delay(0.01);
        event(new RetrievingKey('array', 'shared'));
        event(new CacheHit('array', 'shared', 'fast'));
    }));

    await([$slow, $fast]);
    $parent->end();

    $durations = collect(cacheSpans($tracer, $exporter))
        ->filter(fn (SpanDataInterface $span) => $span->getName() === 'cache.get')
        ->map(fn (SpanDataInterface $span) => $span->getEndEpochNanos() - $span->getStartEpochNanos())
        ->sort()
        ->values();

    expect($durations)->toHaveCount(2)
        ->and($durations[0])->toBeLessThan(10_000_000)
        ->and($durations[1])->toBeGreaterThanOrEqual(40_000_000);
});
