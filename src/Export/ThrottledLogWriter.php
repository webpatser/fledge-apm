<?php

declare(strict_types=1);

namespace Fledge\Apm\Export;

use Closure;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes the OpenTelemetry SDK's internal log lines ("Export failure", ...)
 * into Laravel's logger, each distinct message at most once per window.
 *
 * With a breaker the window is shared across workers (its store), so a down
 * intake logs one line per breaker window instead of one per request. The
 * writer never throws: a logger that fails is ignored.
 */
final class ThrottledLogWriter implements LogWriterInterface
{
    /** @var array<string, float> process-local deadlines when there is no breaker */
    private array $until = [];

    /**
     * @param  Closure(): LoggerInterface  $logger  resolved per write, so a late or rebound logger is used
     */
    public function __construct(
        private readonly Closure $logger,
        private readonly ?ExportCircuitBreaker $breaker = null,
        private readonly float $windowSeconds = 30.0,
    ) {}

    public function write($level, string $message, array $context): void
    {
        if (! $this->claim('log:'.substr(hash('xxh128', $message), 0, 16))) {
            return;
        }

        try {
            ($this->logger)()->log($level, 'OpenTelemetry: '.$message, $context);
        } catch (Throwable) {
            // Logging must never break a flush.
        }
    }

    private function claim(string $key): bool
    {
        if ($this->breaker !== null) {
            return $this->breaker->firstInWindow($key, $this->windowSeconds);
        }

        $now = microtime(true);

        if (($this->until[$key] ?? 0.0) > $now) {
            return false;
        }

        $this->until[$key] = $now + $this->windowSeconds;

        return true;
    }
}
