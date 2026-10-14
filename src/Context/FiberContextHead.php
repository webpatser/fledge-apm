<?php

declare(strict_types=1);

namespace Fledge\Apm\Context;

/**
 * The scope stack of one fiber (or the main context): `$node` is the
 * innermost attached scope, linked to its outer scopes via `previous`.
 *
 * @internal
 */
final class FiberContextHead
{
    public ?FiberContextScope $node = null;

    public function __construct(public readonly FiberContextStorage $storage) {}
}
