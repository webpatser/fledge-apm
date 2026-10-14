<?php

declare(strict_types=1);

namespace Fledge\Apm\Export;

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Throwable;

/**
 * Buffers ended spans and exports them only on forceFlush(), never on the
 * response path.
 *
 * Differences from the SDK's BatchSpanProcessor:
 * - No export from onEnd() (the SDK auto-flushes there once the batch is
 *   full or the schedule delay has passed, inside the request).
 * - forceFlush() swaps the buffer out before any I/O, so spans ending in
 *   other fibers while an export is suspended land in the next batch
 *   instead of being lost or exported twice. Concurrent flushes from
 *   different fibers each export their own batch.
 * - Bounded: a flush stops at its ExportDeadline and after the first failed
 *   chunk, and is skipped while the ExportCircuitBreaker is open, so a down
 *   intake never holds a PHP-FPM or queue worker for long.
 * - Tail-ish keep: with an AlwaysRecordSampler in front of the ratio
 *   sampler, spans the ratio dropped are still recorded (RECORD_ONLY). They
 *   are held per trace until the local root span ends; the trace is
 *   exported when any span failed (`$keepErrors`) or the local root took at
 *   least `$slowThresholdNanos`, otherwise dropped.
 *
 * Limitation: the keep decision is local to this process. A downstream
 * service that saw `sampled=0` in traceparent does not know this process
 * kept the trace, so a kept trace can miss downstream spans.
 */
final class FiberBatchSpanProcessor implements SpanProcessorInterface
{
    /** @var list<SpanDataInterface> spans ready for export */
    private array $ready = [];

    /** @var array<string, list<SpanDataInterface>> unsampled spans by trace id, awaiting their local root */
    private array $pending = [];

    /** @var array<string, true> unsampled traces with a failed span */
    private array $failed = [];

    /** @var array<string, true> unsampled traces kept since the last flush (their late spans are kept too) */
    private array $kept = [];

    private int $pendingCount = 0;

    private int $dropped = 0;

    private bool $closed = false;

    /**
     * @param  int  $flushBudgetMs  time budget of a flush that brings no ExportDeadline of its own
     * @param  ExportCircuitBreaker|null  $breaker  skips exports while open, opened by a failed chunk
     */
    public function __construct(
        private readonly SpanExporterInterface $exporter,
        private readonly bool $keepErrors = true,
        private readonly int $slowThresholdNanos = 0,
        private readonly int $maxQueueSize = 2048,
        private readonly int $maxExportBatchSize = 128,
        private readonly int $flushBudgetMs = 1500,
        private readonly ?ExportCircuitBreaker $breaker = null,
    ) {}

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

    public function onEnd(ReadableSpanInterface $span): void
    {
        if ($this->closed) {
            return;
        }

        $context = $span->getContext();

        if ($context->isSampled()) {
            $this->enqueue($span->toSpanData());

            return;
        }

        $traceId = $context->getTraceId();

        if (isset($this->kept[$traceId])) {
            $this->enqueue($span->toSpanData());

            return;
        }

        if (! $this->keepErrors && $this->slowThresholdNanos <= 0) {
            return;
        }

        $data = $span->toSpanData();
        $this->pending[$traceId][] = $data;
        $this->pendingCount++;

        if ($this->keepErrors && $data->getStatus()->getCode() === StatusCode::STATUS_ERROR) {
            $this->failed[$traceId] = true;
        }

        $parent = $span->getParentContext();

        if ($parent->isValid() && ! $parent->isRemote()) {
            $this->trimPending();

            return;
        }

        // The local root ended: decide for the whole trace.
        $keep = isset($this->failed[$traceId])
            || ($this->slowThresholdNanos > 0 && $span->getDuration() >= $this->slowThresholdNanos);

        $spans = $this->pending[$traceId];
        unset($this->pending[$traceId], $this->failed[$traceId]);
        $this->pendingCount -= count($spans);

        if (! $keep) {
            return;
        }

        $this->kept[$traceId] = true;

        foreach ($spans as $kept) {
            $this->enqueue($kept);
        }
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->export($this->deadline($cancellation, retry: false));
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        $result = $this->export($this->deadline($cancellation, retry: true));
        $this->closed = true;

        return $this->exporter->shutdown($cancellation) && $result;
    }

    /**
     * @return array{ready: int, pending: int, dropped: int}
     */
    public function stats(): array
    {
        return [
            'ready' => count($this->ready),
            'pending' => $this->pendingCount,
            'dropped' => $this->dropped,
        ];
    }

    private function deadline(?CancellationInterface $cancellation, bool $retry): ExportDeadline
    {
        return $cancellation instanceof ExportDeadline
            ? $cancellation
            : ExportDeadline::after($this->flushBudgetMs, $retry);
    }

    /**
     * Export the buffer in chunks within the deadline. The first failed chunk
     * opens the breaker and drops the rest: a struggling intake gets no more
     * requests from this flush. Spans that were not exported count as dropped.
     */
    private function export(ExportDeadline $deadline): bool
    {
        // Swap before any I/O: the export may suspend this fiber.
        $batch = $this->ready;
        $this->ready = [];
        $this->kept = [];

        if ($batch === []) {
            return true;
        }

        $remaining = count($batch);

        if ($this->breaker?->isOpen()) {
            $this->dropped += $remaining;

            return false;
        }

        foreach (array_chunk($batch, max(1, $this->maxExportBatchSize)) as $chunk) {
            if ($deadline->expired()) {
                break;
            }

            try {
                $exported = $this->exporter->export($chunk, $deadline)->await();
            } catch (Throwable) {
                $exported = false;
            }

            if (! $exported) {
                $this->breaker?->recordFailure();

                break;
            }

            $remaining -= count($chunk);
        }

        $this->dropped += $remaining;

        return $remaining === 0;
    }

    private function enqueue(SpanDataInterface $span): void
    {
        if (count($this->ready) >= $this->maxQueueSize) {
            $this->dropped++;

            return;
        }

        $this->ready[] = $span;
    }

    /**
     * Bound the memory held by undecided traces (a root that never ends, a
     * long-running worker): drop the oldest traces first.
     */
    private function trimPending(): void
    {
        while ($this->pendingCount > $this->maxQueueSize && $this->pending !== []) {
            $traceId = array_key_first($this->pending);
            $count = count($this->pending[$traceId]);

            unset($this->pending[$traceId], $this->failed[$traceId]);
            $this->pendingCount -= $count;
            $this->dropped += $count;
        }
    }
}
