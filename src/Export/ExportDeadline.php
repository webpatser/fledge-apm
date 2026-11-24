<?php

declare(strict_types=1);

namespace Fledge\Apm\Export;

use Closure;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;

/**
 * The time budget of one flush, handed down the SDK pipeline as its
 * cancellation (TracerProvider -> FiberBatchSpanProcessor -> SpanExporter ->
 * OtlpHttpTransport), so every chunk, request timeout and retry backoff of
 * that flush draws from the same clock.
 *
 * `$retry` is false on the flushes that run while a worker should be serving
 * (HTTP terminate, queue Looping): one attempt per chunk, nothing more. Only
 * the final flush (WorkerStopping, shutdown) retries, still within budget.
 *
 * Everything that times a flush reads `now()`, the monotonic clock in
 * nanoseconds (`hrtime(true)` unless a clock is given, as tests do).
 */
final readonly class ExportDeadline implements CancellationInterface
{
    /**
     * @param  (Closure(): int)|null  $clock  monotonic nanoseconds; null for hrtime(true)
     */
    public function __construct(
        public int $deadlineNanos,
        public bool $retry = false,
        private ?Closure $clock = null,
    ) {}

    /**
     * @param  (Closure(): int)|null  $clock  monotonic nanoseconds; null for hrtime(true)
     */
    public static function after(int $budgetMilliseconds, bool $retry = false, ?Closure $clock = null): self
    {
        $now = $clock !== null ? $clock() : (int) hrtime(true);

        return new self($now + max(0, $budgetMilliseconds) * 1_000_000, $retry, $clock);
    }

    public function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) hrtime(true);
    }

    public function remainingSeconds(): float
    {
        return max(0.0, ($this->deadlineNanos - $this->now()) / 1_000_000_000);
    }

    public function expired(): bool
    {
        return $this->now() >= $this->deadlineNanos;
    }

    /**
     * Nothing cancels a deadline early; the transport polls remainingSeconds().
     */
    public function subscribe(Closure $callback): string
    {
        return spl_object_hash($callback);
    }

    public function unsubscribe(string $id): void {}
}
