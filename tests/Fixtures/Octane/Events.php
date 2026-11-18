<?php

declare(strict_types=1);

// Stand-ins for the Octane events the provider listens to; Octane is not a
// dev dependency. Loaded with require_once before the provider boots.

namespace Laravel\Octane\Events;

if (! class_exists(RequestReceived::class, false)) {
    final class RequestReceived {}
}

if (! class_exists(WorkerStopping::class, false)) {
    final class WorkerStopping {}
}
