<?php

declare(strict_types=1);

namespace Fledge\Apm\Context;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * One attached context on a fiber's scope stack.
 *
 * Mirrors the detach semantics of OpenTelemetry's internal
 * ContextStorageNode (DETACHED / MISMATCH / INACTIVE flags), so SDK code
 * and DebugScope behave as with the stock storage.
 *
 * @internal
 */
final class FiberContextScope implements ContextStorageScopeInterface
{
    /** @var array<string, mixed> */
    private array $localStorage = [];

    private bool $detached = false;

    public function __construct(
        private readonly ContextInterface $context,
        public readonly FiberContextHead $head,
        public ?FiberContextScope $previous = null,
    ) {}

    public function context(): ContextInterface
    {
        return $this->context;
    }

    public function isDetached(): bool
    {
        return $this->detached;
    }

    public function detach(): int
    {
        if ($this->detached) {
            return ScopeInterface::DETACHED;
        }

        $flags = $this->head !== $this->head->storage->head() ? ScopeInterface::INACTIVE : 0;

        if ($this->head->node === $this) {
            $this->head->node = $this->previous;
            $this->markDetached();

            return $flags;
        }

        // Detached out of order: unlink it from the middle of the stack.
        $depth = 1;

        for ($node = $this->head->node; $node !== null && $node->previous !== $this; $node = $node->previous) {
            $depth++;
        }

        if ($node !== null) {
            $node->previous = $this->previous;
        }

        // A scope missing from its stack was dropped by FiberContextStorage::release()
        // or by Revolt clearing the fiber-local stack of a reused fiber.
        $this->markDetached();

        return $node === null ? ($flags | ScopeInterface::DETACHED) : ($flags | ScopeInterface::MISMATCH | $depth);
    }

    /**
     * Mark this scope detached without touching its stack (used when a whole
     * stack segment is dropped at once).
     */
    public function markDetached(): void
    {
        $this->detached = true;
        $this->previous = null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->localStorage[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->localStorage[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->localStorage[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->localStorage[$offset]);
    }
}
