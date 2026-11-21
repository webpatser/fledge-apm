<?php

declare(strict_types=1);

use Fledge\Apm\ApmServiceProvider;
use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\ThrottledLogWriter;
use Fledge\Apm\Tracer;
use Fledge\Apm\TracerFactory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestReceived;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Fledge\Loop;
use Fledge\Loop\CallbackType;

use function Fledge\Async\delay;

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

/**
 * Boot a fresh enabled provider whose Tracer exports to `$exporter`, which
 * counts its export calls.
 *
 * @param  array<string, int>  $batch
 */
function bootCountingApm(InMemoryExporter $exporter, array $batch = []): Tracer
{
    config()->set('apm.enabled', true);
    config()->set('apm.breaker.enabled', false);
    config()->set('apm.keep.slow_ms', 0);
    config()->set('apm.batch', [...config('apm.batch'), ...$batch]);
    config()->set('apm.instrumentation', array_fill_keys(array_keys(ApmServiceProvider::INSTRUMENTATIONS), false));

    $provider = new ApmServiceProvider(app());
    $provider->register();

    $tracer = TracerFactory::make(config('apm'), app(FiberContextStorage::class), $exporter);
    app()->instance(Tracer::class, $tracer);

    $provider->boot();

    return $tracer;
}

function countingExporter(): InMemoryExporter
{
    return new class extends InMemoryExporter
    {
        public int $exports = 0;

        protected function doExport(iterable $spans): bool
        {
            $this->exports++;

            return parent::doExport($spans);
        }
    };
}

/**
 * One request: a span, then the app's terminating callbacks.
 */
function simulateRequest(Tracer $tracer, string $name = 'request'): void
{
    $tracer->span($name, fn () => null);
    app()->terminate();
}

it('counts the spans waiting for export', function () {
    $tracer = bootCountingApm(countingExporter());

    expect($tracer->bufferedSpans())->toBe(0);

    $tracer->span('one', fn () => null);
    $tracer->span('two', fn () => null);

    expect($tracer->bufferedSpans())->toBe(2);

    $tracer->flush();

    expect($tracer->bufferedSpans())->toBe(0)
        ->and(Tracer::disabled()->bufferedSpans())->toBe(0);
});

it('exports after every request outside Octane', function () {
    $exporter = countingExporter();
    $tracer = bootCountingApm($exporter);

    simulateRequest($tracer);
    simulateRequest($tracer);
    simulateRequest($tracer);

    expect($exporter->exports)->toBe(3)
        ->and($exporter->getSpans())->toHaveCount(3)
        ->and($tracer->bufferedSpans())->toBe(0);
});

it('exports on a full batch instead of after every request under Octane', function () {
    require_once __DIR__.'/../Fixtures/Octane/Events.php';

    $exporter = countingExporter();
    $tracer = bootCountingApm($exporter, ['max_export_batch_size' => 4, 'worker_flush_interval' => 3600]);

    foreach (range(1, 3) as $request) {
        event(new RequestReceived);
        simulateRequest($tracer, "request {$request}");
    }

    expect($exporter->exports)->toBe(0)
        ->and($tracer->bufferedSpans())->toBe(3);

    event(new RequestReceived);
    simulateRequest($tracer, 'request 4');

    expect($exporter->exports)->toBe(1)
        ->and(array_map(fn ($span) => $span->getName(), $exporter->getSpans()))
        ->toBe(['request 1', 'request 2', 'request 3', 'request 4'])
        ->and($tracer->bufferedSpans())->toBe(0);
});

it('exports what is left when the Octane worker stops', function () {
    require_once __DIR__.'/../Fixtures/Octane/Events.php';

    $exporter = countingExporter();
    $tracer = bootCountingApm($exporter, ['worker_flush_interval' => 3600]);

    event(new RequestReceived);
    simulateRequest($tracer);
    event(new RequestReceived);
    simulateRequest($tracer);

    expect($exporter->exports)->toBe(0);

    event(new Laravel\Octane\Events\WorkerStopping);

    expect($exporter->exports)->toBe(1)
        ->and($exporter->getSpans())->toHaveCount(2)
        ->and($tracer->bufferedSpans())->toBe(0);
});

/**
 * Unreferenced repeat timers on the event loop.
 *
 * @return list<string>
 */
function unreferencedRepeatTimers(): array
{
    return array_values(array_filter(
        Loop::getIdentifiers(),
        fn (string $id): bool => Loop::getType($id) === CallbackType::Repeat
            && Loop::isEnabled($id)
            && ! Loop::isReferenced($id),
    ));
}

it('flushes an idle worker on an unreferenced timer when an event loop runs', function () {
    require_once __DIR__.'/../Fixtures/Octane/Events.php';

    delay(0); // the event loop now runs, as in the Fledge server and torque workers

    expect(Loop::getDriver()->isRunning())->toBeTrue();

    $before = unreferencedRepeatTimers();
    $exporter = countingExporter();
    $tracer = bootCountingApm($exporter, ['worker_flush_interval' => 1]);

    event(new RequestReceived);
    simulateRequest($tracer);
    event(new RequestReceived);
    simulateRequest($tracer);

    $timers = array_values(array_diff(unreferencedRepeatTimers(), $before));

    expect($exporter->exports)->toBe(0)
        ->and($tracer->bufferedSpans())->toBe(2)
        ->and($timers)->toHaveCount(1);

    delay(1.2); // idle: no request ends, only the timer can flush

    expect($exporter->exports)->toBe(1)
        ->and($exporter->getSpans())->toHaveCount(2)
        ->and($tracer->bufferedSpans())->toBe(0);

    delay(1.2); // nothing buffered: the timer does nothing

    expect($exporter->exports)->toBe(1)
        ->and(array_values(array_diff(unreferencedRepeatTimers(), $before)))->toBe($timers);

    Loop::cancel($timers[0]);
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
