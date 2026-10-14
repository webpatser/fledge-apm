<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Tests\TestCase;
use Fledge\Apm\Tracer;
use Fledge\Apm\TracerFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Instrumentation', 'Integration');

/**
 * An enabled Tracer exporting into an in-memory exporter.
 *
 * @param  array<string, mixed>  $config
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function memoryTracer(array $config = []): array
{
    $storage = new FiberContextStorage;
    $exporter = new InMemoryExporter;

    $tracer = TracerFactory::make(array_replace_recursive([
        'enabled' => true,
        'sample_rate' => 1.0,
        'keep' => ['errors' => true, 'slow_ms' => 0],
        'service' => ['name' => 'fledge-apm-tests'],
    ], $config), $storage, $exporter);

    return [$tracer, $exporter, $storage];
}
