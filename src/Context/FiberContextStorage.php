<?php

declare(strict_types=1);

namespace Fledge\Apm\Context;

use Fiber;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use Revolt\EventLoop\FiberLocal;

/**
 * OpenTelemetry context storage with one scope stack per fiber, safe under
 * Revolt's fiber reuse and parallel Fledge\Async\async() fibers.
 *
 * Why not the SDK's FiberBoundContextStorage:
 * - It keys heads on the Fiber object for the fiber's whole lifetime. Revolt
 *   reuses one callback fiber for every queued callback that completes
 *   without suspending, so a scope a callback leaks (never detached) bleeds
 *   into the next, unrelated callback on that fiber.
 * - A fresh fiber starts with no head: current() triggers E_USER_WARNING
 *   (which Laravel turns into an ErrorException) and falls back to {main},
 *   not to the fiber that spawned it.
 *
 * This storage keys stacks with Revolt's FiberLocal, which the event loop
 * clears after every callback and microtask (the unit async() runs in). A
 * reused callback fiber therefore always starts with an empty stack, the
 * same guarantee UsesNativePdoPool gets from fiber plus scope-depth keying.
 * The main context ({main}) keeps its own stack.
 *
 * Inheritance into async() fibers is explicit: capture() in the parent and
 * restore() / release() in the child (Propagation wires that into the
 * fledge-fiber async() hook). A fiber nobody restored starts at the root
 * context, without a warning.
 */
final class FiberContextStorage implements ContextStorageInterface, ExecutionContextAwareInterface
{
    private readonly FiberContextHead $main;

    /** @var FiberLocal<FiberContextHead> */
    private readonly FiberLocal $heads;

    public function __construct()
    {
        $this->main = new FiberContextHead($this);
        $this->heads = new FiberLocal(fn (): FiberContextHead => new FiberContextHead($this));
    }

    /**
     * The scope stack of the current fiber (or the main context).
     */
    public function head(): FiberContextHead
    {
        return Fiber::getCurrent() === null ? $this->main : $this->heads->get();
    }

    public function scope(): ?ContextStorageScopeInterface
    {
        return $this->head()->node;
    }

    public function current(): ContextInterface
    {
        return $this->head()->node?->context() ?? Context::getRoot();
    }

    public function attach(ContextInterface $context): ContextStorageScopeInterface
    {
        $head = $this->head();

        return $head->node = new FiberContextScope($context, $head, $head->node);
    }

    /**
     * Snapshot the current fiber's context for a child fiber, or null when
     * there is nothing to inherit (the root context).
     */
    public function capture(): ?ContextInterface
    {
        $context = $this->current();

        return $context === Context::getRoot() ? null : $context;
    }

    /**
     * Attach a captured context as the base of the current (child) fiber.
     * Pass the returned scope to release() when the child's work ends.
     */
    public function restore(ContextInterface $context): FiberContextScope
    {
        /** @var FiberContextScope */
        return $this->attach($context);
    }

    /**
     * Drop the restored base scope and everything still attached above it
     * (scopes the child leaked), restoring the stack as it was before
     * restore(). Safe to call after Revolt already cleared the stack.
     */
    public function release(FiberContextScope $base): void
    {
        if ($base->isDetached()) {
            return;
        }

        $head = $base->head;
        $node = $head->node;
        $onStack = false;

        for ($probe = $node; $probe !== null; $probe = $probe->previous) {
            if ($probe === $base) {
                $onStack = true;

                break;
            }
        }

        if (! $onStack) {
            $base->markDetached();

            return;
        }

        while ($node !== null && $node !== $base) {
            $previous = $node->previous;
            $node->markDetached();
            $node = $previous;
        }

        $head->node = $base->previous;
        $base->markDetached();
    }

    /**
     * Clear the main context's ({main}) stack: every scope still attached
     * there leaked from a finished unit of work (a job, an Octane request)
     * and would otherwise parent the next one.
     *
     * Called between units of work (queue Looping, Octane RequestTerminated).
     * Fiber stacks are left alone; Revolt clears those itself. A span whose
     * scope was cleared still ends and exports normally: ActiveSpan::end()
     * then finds its scope already detached and leaves the stack untouched.
     *
     * @return int the number of scopes cleared
     */
    public function reset(): int
    {
        $cleared = 0;

        for ($node = $this->main->node; $node !== null; $node = $previous) {
            $previous = $node->previous;
            $node->markDetached();
            $cleared++;
        }

        $this->main->node = null;

        return $cleared;
    }

    /**
     * Number of scopes attached in the current fiber (diagnostics and tests).
     */
    public function depth(): int
    {
        $depth = 0;

        for ($node = $this->head()->node; $node !== null; $node = $node->previous) {
            $depth++;
        }

        return $depth;
    }

    /**
     * ExecutionContextAwareInterface is only driven by the SDK's FFI fiber
     * observer (OTEL_PHP_FIBERS_ENABLED). Stacks here follow Fiber::getCurrent()
     * directly, so fork/switch/destroy have nothing to do.
     */
    public function fork(int|string $id): void {}

    public function switch(int|string $id): void {}

    public function destroy(int|string $id): void {}
}
