<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;

/**
 * One source of spans (HTTP kernel, queue, database, ...). The provider
 * calls register() once at boot, only when APM is enabled, so a disabled
 * install registers no listeners at all.
 *
 * Implementations record spans exclusively through Tracer (span(),
 * startSpan()/ActiveSpan::end(), recordSpan()).
 */
interface Instrumentation
{
    public function register(Application $app, Tracer $tracer): void;
}
