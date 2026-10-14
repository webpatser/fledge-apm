<?php

declare(strict_types=1);

namespace Fledge\Apm\Context;

use Closure;
use OpenTelemetry\Context\ContextInterface;

/**
 * Carries the active trace context from a parent fiber into the fibers it
 * spawns with Fledge\Async\async().
 *
 * With fledge-fiber's async() context hook (Fledge\Async\FiberContext,
 * planned for the next fledge-fiber patch) register() makes every async()
 * fiber inherit the parent's span, so parallel Livewire cards and
 * FiberDB::concurrent() work become sibling spans. Without the hook,
 * wrap() does the same for one closure.
 */
final class Propagation
{
    public const string NAME = 'fledge-apm';

    public function __construct(private readonly FiberContextStorage $storage) {}

    /**
     * Register with fledge-fiber's async() hook when it is available.
     *
     * @return bool whether the hook exists and the propagator was registered
     */
    public function register(): bool
    {
        $hook = 'Fledge\\Async\\FiberContext';

        if (! class_exists($hook)) {
            return false;
        }

        $hook::register(self::NAME, $this->capture(...), $this->restore(...));

        return true;
    }

    /**
     * Snapshot taken in the parent fiber; null when there is nothing to inherit.
     */
    public function capture(): ?ContextInterface
    {
        return $this->storage->capture();
    }

    /**
     * Run in the child fiber before its closure; returns the cleanup to run
     * in the child's `finally`, or null when there was nothing to restore.
     *
     * @return (Closure(): void)|null
     */
    public function restore(mixed $snapshot): ?Closure
    {
        if (! $snapshot instanceof ContextInterface) {
            return null;
        }

        $base = $this->storage->restore($snapshot);

        return fn () => $this->storage->release($base);
    }

    /**
     * Bind a closure to the current context: wherever (and in whichever
     * fiber) it runs, it starts with the context active right now.
     *
     * @template T
     *
     * @param  Closure(mixed ...): T  $closure
     * @return Closure(mixed ...): T
     */
    public function wrap(Closure $closure): Closure
    {
        $snapshot = $this->capture();

        if ($snapshot === null) {
            return $closure;
        }

        return function (mixed ...$args) use ($snapshot, $closure): mixed {
            $cleanup = $this->restore($snapshot);

            try {
                return $closure(...$args);
            } finally {
                $cleanup?->__invoke();
            }
        };
    }
}
