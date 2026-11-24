<?php

declare(strict_types=1);

namespace Fledge\Apm\Metrics;

use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Caps the distinct attribute sets per instrument. The SDK has no limit, so
 * an attribute that slips through with unbounded values (a path, an id)
 * would grow memory and the documents per export without bound.
 *
 * Usage, once per recording:
 *
 *     $histogram->record($seconds, $guard->attributes('http.client.request.duration', $attributes));
 *
 * The first `limit` distinct sets pass unchanged; every further set is
 * replaced by {otel.metric.overflow: true}. The first overflow per
 * instrument is logged once per process (through the throttled log writer,
 * so also at most once per window across workers).
 *
 * Reset policy: with delta temporality (the default) the seen sets are
 * cleared right before each collect, so the cap is distinct sets per export
 * interval, which is what bounds the documents Elasticsearch receives per
 * interval. With cumulative or lowmemory temporality the SDK keeps every set
 * for the life of the process, so the guard does too (never reset).
 *
 * Cost on the hot path: one ksort of the (small) attribute array and one
 * xxh128 over the joined scalar values; memory is at most `limit` 16-byte
 * keys per instrument.
 */
final class CardinalityGuard
{
    public const string OVERFLOW_ATTRIBUTE = 'otel.metric.overflow';

    /** @var array<non-empty-string, true> */
    public const array OVERFLOW = [self::OVERFLOW_ATTRIBUTE => true];

    /** @var array<string, array<string, true>> instrument name to seen set keys */
    private array $seen = [];

    /** @var array<string, true> instruments whose overflow was logged */
    private array $logged = [];

    public function __construct(
        private readonly int $limit = 200,
        private readonly ?LogWriterInterface $log = null,
    ) {}

    /**
     * The attributes to record: `$attributes` unchanged while the instrument
     * is under its limit (or the set was seen before), else the overflow set.
     *
     * @param  array<non-empty-string, mixed>  $attributes
     * @return array<non-empty-string, mixed>
     */
    public function attributes(string $instrument, array $attributes): array
    {
        $key = self::key($attributes);

        if (isset($this->seen[$instrument][$key])) {
            return $attributes;
        }

        if (count($this->seen[$instrument] ?? []) < $this->limit) {
            $this->seen[$instrument][$key] = true;

            return $attributes;
        }

        $this->logOverflow($instrument);

        return self::OVERFLOW;
    }

    /**
     * Forget the seen sets (start of a new delta interval). Overflow log
     * lines stay once per process.
     */
    public function reset(): void
    {
        $this->seen = [];
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Distinct sets admitted for the instrument since the last reset.
     */
    public function count(string $instrument): int
    {
        return count($this->seen[$instrument] ?? []);
    }

    /**
     * Order-independent key of an attribute set. Values are typed so that
     * 1, "1" and true are distinct sets, as they are for the SDK.
     *
     * @param  array<mixed>  $attributes
     */
    private static function key(array $attributes): string
    {
        if ($attributes === []) {
            return '';
        }

        ksort($attributes);
        $joined = '';

        foreach ($attributes as $name => $value) {
            $joined .= $name."\x1e".self::scalar($value)."\x1f";
        }

        return hash('xxh128', $joined, true);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => 's'.$value,
            is_int($value) => 'i'.$value,
            is_bool($value) => $value ? 'T' : 'F',
            is_float($value) => 'd'.$value,
            $value === null => 'n',
            is_array($value) => 'a'.implode("\x1d", array_map(self::scalar(...), $value)),
            default => 'o'.get_debug_type($value),
        };
    }

    private function logOverflow(string $instrument): void
    {
        if (isset($this->logged[$instrument])) {
            return;
        }

        $this->logged[$instrument] = true;

        try {
            $this->log?->write(LogLevel::WARNING, sprintf(
                'metric %s exceeded %d distinct attribute sets; further sets are recorded as %s=true',
                $instrument,
                $this->limit,
                self::OVERFLOW_ATTRIBUTE,
            ), []);
        } catch (Throwable) {
            // Logging must never break a recording.
        }
    }
}
