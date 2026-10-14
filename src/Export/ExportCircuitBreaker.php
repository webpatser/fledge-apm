<?php

declare(strict_types=1);

namespace Fledge\Apm\Export;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Stops every worker from exporting for a while once one export failed, so
 * a down intake costs one failed flush per window instead of a timeout on
 * every request.
 *
 * State is shared through APCu (per host, a cheap shared-memory read) or a
 * Laravel cache store, and mirrored in process. Any failure of the shared
 * store is swallowed: the breaker then works per process for the rest of
 * its life. It never throws.
 *
 * Values are epoch-second deadlines, so the window can be shorter than the
 * one-second TTL granularity of cache stores.
 */
final class ExportCircuitBreaker
{
    public const string STORE_AUTO = 'auto';

    public const string STORE_APCU = 'apcu';

    public const string STORE_LOCAL = 'local';

    private float $localUntil = 0.0;

    /** @var array<string, float> process-local firstInWindow() deadlines */
    private array $localFlags = [];

    private Repository|string|null $backend = null;

    private bool $resolved = false;

    /**
     * @param  float  $openSeconds  how long the breaker stays open after a failure
     * @param  Closure(): Repository|'apcu'|null  $store  shared state; null keeps it in process
     * @param  string  $key  cache key prefix (per intake endpoint)
     */
    public function __construct(
        public readonly float $openSeconds = 30.0,
        private readonly Closure|string|null $store = null,
        private readonly string $key = 'fledge-apm:breaker',
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `apm.breaker` config
     * @param  (Closure(string|null): Repository)|null  $cacheStore  resolves a Laravel cache store by name (null for the default)
     */
    public static function fromConfig(array $config, string $endpoint, ?Closure $cacheStore = null): self
    {
        $store = $config['store'] ?? null;
        $store = is_string($store) && $store !== '' ? $store : self::STORE_AUTO;
        $apcu = self::apcuAvailable();

        $backend = match (true) {
            $store === self::STORE_LOCAL => null,
            $store === self::STORE_APCU => $apcu ? self::STORE_APCU : null,
            $store === self::STORE_AUTO && $apcu => self::STORE_APCU,
            $cacheStore === null => null,
            $store === self::STORE_AUTO => static fn (): Repository => $cacheStore(null),
            default => static fn (): Repository => $cacheStore($store),
        };

        return new self(
            openSeconds: max(0.0, (float) ($config['seconds'] ?? 30)),
            store: $backend,
            key: 'fledge-apm:breaker:'.substr(hash('xxh128', $endpoint), 0, 16),
        );
    }

    public static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Whether exports are suspended right now.
     */
    public function isOpen(): bool
    {
        $now = microtime(true);

        if ($this->localUntil > $now) {
            return true;
        }

        $until = $this->read($this->key);

        if ($until > $now) {
            $this->localUntil = $until;

            return true;
        }

        return false;
    }

    /**
     * Open the breaker for every worker sharing the store.
     */
    public function recordFailure(): void
    {
        $this->localUntil = microtime(true) + $this->openSeconds;
        $this->write($this->key, $this->localUntil, $this->openSeconds);
    }

    /**
     * Close the breaker early (tests, manual recovery).
     */
    public function close(): void
    {
        $this->localUntil = 0.0;
        $this->localFlags = [];
        $this->write($this->key, 0.0, 1.0);
    }

    /**
     * True for the first caller of `$name` in the current window (defaults to
     * the open window), across workers when the store is shared. Used to
     * throttle log lines.
     */
    public function firstInWindow(string $name, ?float $seconds = null): bool
    {
        $seconds ??= $this->openSeconds;
        $now = microtime(true);

        if (($this->localFlags[$name] ?? 0.0) > $now) {
            return false;
        }

        $this->localFlags[$name] = $now + $seconds;
        $key = $this->key.':'.$name;

        if ($this->add($key, $now + $seconds, $seconds)) {
            return true;
        }

        // The entry may outlive its deadline by up to a second (TTL rounding).
        if ($this->read($key) > $now) {
            return false;
        }

        $this->write($key, $now + $seconds, $seconds);

        return true;
    }

    private function read(string $key): float
    {
        return $this->attempt(static function (Repository|string $backend) use ($key): float {
            $value = $backend === self::STORE_APCU ? apcu_fetch($key) : $backend->get($key);

            return is_numeric($value) ? (float) $value : 0.0;
        }, 0.0);
    }

    private function write(string $key, float $until, float $seconds): void
    {
        $ttl = max(1, (int) ceil($seconds));

        $this->attempt(static function (Repository|string $backend) use ($key, $until, $ttl): bool {
            return $backend === self::STORE_APCU ? apcu_store($key, $until, $ttl) : $backend->put($key, $until, $ttl);
        }, false);
    }

    /**
     * Atomic add-if-absent. Without a shared store the local flag decided.
     */
    private function add(string $key, float $until, float $seconds): bool
    {
        $ttl = max(1, (int) ceil($seconds));

        return $this->attempt(static function (Repository|string $backend) use ($key, $until, $ttl): bool {
            return $backend === self::STORE_APCU ? apcu_add($key, $until, $ttl) : $backend->add($key, $until, $ttl);
        }, true);
    }

    /**
     * @template T
     *
     * @param  Closure(Repository|string): T  $operation
     * @param  T  $fallback
     * @return T
     */
    private function attempt(Closure $operation, mixed $fallback): mixed
    {
        try {
            $backend = $this->backend();

            return $backend === null ? $fallback : $operation($backend);
        } catch (Throwable) {
            // A broken store degrades to process-local state for good.
            $this->backend = null;
            $this->resolved = true;

            return $fallback;
        }
    }

    private function backend(): Repository|string|null
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $this->backend = $this->store instanceof Closure ? ($this->store)() : $this->store;
        }

        return $this->backend;
    }
}
