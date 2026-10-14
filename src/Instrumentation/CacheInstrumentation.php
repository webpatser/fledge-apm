<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fiber;
use Fledge\Apm\Tracer;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use OpenTelemetry\API\Trace\SpanKind;
use stdClass;
use WeakMap;

/**
 * One span per cache get, put and forget.
 *
 * The cache repository fires a "starting" event (RetrievingKey, WritingKey,
 * ForgettingKey) and then an "outcome" event (CacheHit / CacheMissed,
 * KeyWritten / KeyWriteFailed, KeyForgotten / KeyForgetFailed) in the same
 * fiber. The start event only stores a timestamp; the outcome event records
 * one finished span back-dated to it. That is one span object per operation
 * (a span event on the parent would be cheaper still but shows up in no APM
 * UI). An outcome without a start event (getMultiple() fires per-key
 * CacheHit/CacheMissed only) is recorded as a zero-duration span.
 *
 * Start timestamps live in a WeakMap keyed by the current Fiber (a sentinel
 * object for the main context), so parallel fibers never see each other's
 * pending operations.
 *
 * Options (all under `apm.options.cache`):
 * - `record_keys` (bool, default false): set `cache.key`. Keys can carry user ids, emails or tokens.
 *
 * Operations outside any trace are not recorded.
 */
final class CacheInstrumentation implements Instrumentation
{
    private const int MAX_PENDING_PER_FIBER = 128;

    /** @var WeakMap<object, array<string, int>> */
    private WeakMap $pending;

    private readonly stdClass $main;

    private bool $recordKeys = false;

    public function __construct()
    {
        $this->pending = new WeakMap;
        $this->main = new stdClass;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->recordKeys = (bool) $app['config']->get('apm.options.cache.record_keys', false);

        /** @var Dispatcher $events */
        $events = $app['events'];

        foreach ([RetrievingKey::class => 'get', WritingKey::class => 'put', ForgettingKey::class => 'forget'] as $class => $operation) {
            $events->listen($class, fn (CacheEvent $event) => $this->started($operation, $event));
        }

        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->finished($tracer, 'get', $event, hit: true));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->finished($tracer, 'get', $event, hit: false));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->finished($tracer, 'put', $event, ttl: $event->seconds));
        $events->listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $this->finished($tracer, 'put', $event, failed: true));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->finished($tracer, 'forget', $event));
        $events->listen(KeyForgetFailed::class, fn (KeyForgetFailed $event) => $this->finished($tracer, 'forget', $event, failed: true));
    }

    private function started(string $operation, CacheEvent $event): void
    {
        $fiber = Fiber::getCurrent() ?? $this->main;
        $pending = $this->pending[$fiber] ?? [];

        // A start without an outcome (the store threw) must not pile up.
        if (count($pending) >= self::MAX_PENDING_PER_FIBER) {
            array_shift($pending);
        }

        $pending[self::slot($operation, $event)] = Tracer::now();
        $this->pending[$fiber] = $pending;
    }

    private function finished(Tracer $tracer, string $operation, CacheEvent $event, ?bool $hit = null, ?int $ttl = null, bool $failed = false): void
    {
        $fiber = Fiber::getCurrent() ?? $this->main;
        $slot = self::slot($operation, $event);
        $start = $this->pending[$fiber][$slot] ?? null;

        if ($start !== null) {
            $pending = $this->pending[$fiber];
            unset($pending[$slot]);
            $this->pending[$fiber] = $pending;
        }

        if (! $tracer->currentSpan()->getContext()->isValid()) {
            return;
        }

        $end = Tracer::now();

        $span = $tracer->startSpan(
            name: "cache.{$operation}",
            kind: SpanKind::KIND_INTERNAL,
            attributes: array_filter([
                'cache.operation' => $operation,
                'cache.store' => $event->storeName,
                'cache.hit' => $hit,
                'cache.ttl' => $ttl,
                'cache.key' => $this->recordKeys ? $event->key : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            startEpochNanos: $start ?? $end,
            activate: false,
        );

        if ($failed) {
            $span->setError("cache {$operation} failed");
        }

        $span->end($end);
    }

    private static function slot(string $operation, CacheEvent $event): string
    {
        return "{$operation}|{$event->storeName}|{$event->key}";
    }
}
