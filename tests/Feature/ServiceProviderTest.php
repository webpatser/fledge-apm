<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Export\ThrottledLogWriter;
use Fledge\Apm\Tracer;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Behavior\Internal\Logging;

it('is a no-op with zero listeners when disabled', function () {
    $tracer = app(Tracer::class);

    expect(config('apm.enabled'))->toBeFalse()
        ->and($tracer->enabled())->toBeFalse()
        ->and(Event::hasListeners(Looping::class))->toBeFalse()
        ->and(Event::hasListeners(WorkerStopping::class))->toBeFalse()
        ->and($tracer->span('ignored', fn () => 42))->toBe(42)
        ->and($tracer->startSpan('ignored')->isRecording())->toBeFalse()
        ->and($tracer->flush())->toBeTrue();
});

it('builds an enabled tracer and the worker flush listeners when enabled', function () {
    config()->set('apm.enabled', true);
    config()->set('apm.exporter', 'memory');

    $provider = new ApmServiceProvider(app());
    $provider->register();
    $provider->boot();

    $tracer = app(Tracer::class);

    expect($tracer->enabled())->toBeTrue()
        ->and(Event::hasListeners(Looping::class))->toBeTrue()
        ->and(Event::hasListeners(WorkerStopping::class))->toBeTrue()
        ->and($tracer->span('recorded', fn ($span) => $span->isRecording()))->toBeTrue();
});

/**
 * Register and boot a fresh provider with APM enabled on the memory exporter.
 */
function bootEnabledApm(): Tracer
{
    config()->set('apm.enabled', true);
    config()->set('apm.exporter', 'memory');
    config()->set('apm.breaker.store', 'local');
    config()->set('apm.instrumentation', array_fill_keys(array_keys(ApmServiceProvider::INSTRUMENTATIONS), false));

    $provider = new ApmServiceProvider(app());
    $provider->register();
    $provider->boot();

    return app(Tracer::class);
}

it('clears scopes leaked on the main context before the next job', function () {
    $tracer = bootEnabledApm();

    $tracer->startSpan('leaked job span'); // never ended

    expect($tracer->storage()->depth())->toBe(1);

    // The worker pauses when a Looping listener returns false.
    $result = app('events')->until(new Looping('sync', 'default'));

    expect($result)->not->toBeFalse()
        ->and($tracer->storage()->depth())->toBe(0);
});

it('routes SDK log lines through the throttled Laravel log writer', function () {
    bootEnabledApm();

    expect(Logging::logWriter())->toBeInstanceOf(ThrottledLogWriter::class);

    Logging::reset();
});

it('warns once per process when async() fibers cannot inherit the trace context', function () {
    (new ReflectionProperty(ApmServiceProvider::class, 'warnedPropagation'))->setValue(null, false);
    Log::spy();

    bootEnabledApm();
    bootEnabledApm();

    Logging::reset();

    if (class_exists('Fledge\\Async\\FiberContext')) {
        Log::shouldNotHaveReceived('warning');

        return;
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(Mockery::on(fn (string $message): bool => str_contains($message, '13.36.0.2')));
});
