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
 */
final readonly class ExportDeadline implements CancellationInterface
{
    public function __construct(
        public int $deadlineNanos,
        public bool $retry = false,
    ) {}

    public static function after(int $budgetMilliseconds, bool $retry = false): self
    {
        return new self(hrtime(true) + max(0, $budgetMilliseconds) * 1_000_000, $retry);
    }

    public function remainingSeconds(): float
    {
        return max(0.0, ($this->deadlineNanos - hrtime(true)) / 1_000_000_000);
    }

    public function expired(): bool
    {
        return hrtime(true) >= $this->deadlineNanos;
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
