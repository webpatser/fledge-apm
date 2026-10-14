<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Instrumentation\ConsoleInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Support\Facades\Artisan;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function consoleApm(): array
{
    [$tracer, $exporter, $storage] = memoryTracer();

    (new ConsoleInstrumentation)->register(app(), $tracer);

    return [$tracer, $exporter, $storage];
}

/**
 * @return list<SpanDataInterface>
 */
function consoleExported(Tracer $tracer, InMemoryExporter $exporter): array
{
    expect($tracer->flush())->toBeTrue();

    return $exporter->getSpans();
}

function runCommandEvents(string $command, int $exitCode = 0, ?Closure $between = null): void
{
    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting($command, $input, $output));

    if ($between !== null) {
        $between();
    }

    event(new CommandFinished($command, $input, $output, $exitCode));
}

it('records a transaction per command with the exit code', function () {
    [$tracer, $exporter, $storage] = consoleApm();

    runCommandEvents('app:report');

    $spans = consoleExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('artisan app:report')
        ->and($spans[0]->getParentContext()->isValid())->toBeFalse()
        ->and($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($spans[0]->getAttributes()->get('laravel.console.command'))->toBe('app:report')
        ->and($spans[0]->getAttributes()->get('process.exit.code'))->toBe(0)
        ->and($storage->depth())->toBe(0);
});

it('marks a non-zero exit code as an error', function () {
    [$tracer, $exporter] = consoleApm();

    runCommandEvents('app:report', 2);

    $spans = consoleExported($tracer, $exporter);

    expect($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans[0]->getAttributes()->get('process.exit.code'))->toBe(2);
});

it('skips long-running and noisy commands by default', function (string $command) {
    [$tracer, $exporter, $storage] = consoleApm();

    runCommandEvents($command);

    expect(consoleExported($tracer, $exporter))->toBeEmpty()
        ->and($storage->depth())->toBe(0);
})->with([
    'queue:work',
    'queue:listen',
    'horizon',
    'horizon:supervisor',
    'pulse:work',
    'pulse:check',
    'schedule:work',
    'tinker',
    'list',
]);

it('takes the ignore list from config and replaces the defaults', function () {
    config()->set('apm.console.ignore', ['custom:*']);

    [$tracer, $exporter] = consoleApm();

    runCommandEvents('custom:sync');
    runCommandEvents('queue:work');

    $spans = consoleExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('artisan queue:work');
});

it('nests a command run inside another span under it', function () {
    [$tracer, $exporter] = consoleApm();

    $tracer->span('parent', fn () => runCommandEvents('app:child'));

    $spans = consoleExported($tracer, $exporter);
    $byName = collect($spans)->keyBy(fn (SpanDataInterface $span) => $span->getName());

    expect($byName['artisan app:child']->getParentSpanId())->toBe($byName['parent']->getSpanId());
});

it('keeps nested command calls apart', function () {
    [$tracer, $exporter, $storage] = consoleApm();

    runCommandEvents('app:outer', 0, fn () => runCommandEvents('app:inner', 1));

    $byName = collect(consoleExported($tracer, $exporter))->keyBy(fn (SpanDataInterface $span) => $span->getName());

    expect($byName)->toHaveCount(2)
        ->and($byName['artisan app:inner']->getParentSpanId())->toBe($byName['artisan app:outer']->getSpanId())
        ->and($byName['artisan app:inner']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($byName['artisan app:outer']->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
        ->and($storage->depth())->toBe(0);
});

it('ignores a finish event for a command that never started', function () {
    [$tracer, $exporter] = consoleApm();

    event(new CommandFinished('app:ghost', new ArrayInput([]), new NullOutput, 0));

    expect(consoleExported($tracer, $exporter))->toBeEmpty();
});

it('ends the span of an artisan call that throws, as failed', function () {
    [$tracer, $exporter, $storage] = consoleApm();

    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    Artisan::command('apm:explode', fn () => throw new RuntimeException('command exploded'));

    expect(fn () => Artisan::call('apm:explode'))->toThrow(RuntimeException::class, 'command exploded');

    $spans = consoleExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('artisan apm:explode')
        ->and($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans[0]->getAttributes()->get('process.exit.code'))->toBe(1)
        ->and($tracer->currentSpan()->getContext()->isValid())->toBeFalse()
        ->and($storage->depth())->toBe(0);
});

it('ends a command that never finished when the next command starts', function () {
    [$tracer, $exporter, $storage] = consoleApm();

    // The input goes out of scope with the closure: the command is gone
    // without a CommandFinished.
    (function (): void {
        event(new CommandStarting('app:lost', new ArrayInput([]), new NullOutput));
    })();

    expect($storage->depth())->toBe(1);

    runCommandEvents('app:next');

    $byName = collect(consoleExported($tracer, $exporter))->keyBy(fn (SpanDataInterface $span) => $span->getName());

    expect($byName)->toHaveCount(2)
        ->and($byName['artisan app:lost']->hasEnded())->toBeTrue()
        // the next command is a new root, not a child of the abandoned one
        ->and($byName['artisan app:next']->getParentContext()->isValid())->toBeFalse()
        ->and($byName['artisan app:next']->getTraceId())->not->toBe($byName['artisan app:lost']->getTraceId())
        ->and($storage->depth())->toBe(0);
});

it('ends a nested command that never finished when its outer command finishes', function () {
    [$tracer, $exporter, $storage] = consoleApm();

    $input = new ArrayInput([]);
    $inner = new ArrayInput([]);

    event(new CommandStarting('app:outer', $input, new NullOutput));
    event(new CommandStarting('app:inner', $inner, new NullOutput));
    event(new CommandFinished('app:outer', $input, new NullOutput, 0));

    $byName = collect(consoleExported($tracer, $exporter))->keyBy(fn (SpanDataInterface $span) => $span->getName());

    expect($byName)->toHaveCount(2)
        ->and($byName['artisan app:inner']->getParentSpanId())->toBe($byName['artisan app:outer']->getSpanId())
        ->and($byName['artisan app:outer']->getAttributes()->get('process.exit.code'))->toBe(0)
        ->and($storage->depth())->toBe(0);
});

it('ends every open command span on terminating', function () {
    [$tracer, $exporter, $storage] = consoleApm();

    $input = new ArrayInput([]);

    event(new CommandStarting('app:hanging', $input, new NullOutput));

    expect($storage->depth())->toBe(1);

    event(new Terminating);
    event(new Terminating);

    $spans = consoleExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('artisan app:hanging')
        ->and($storage->depth())->toBe(0);
});

it('traces a real artisan call end to end', function () {
    [$tracer, $exporter] = consoleApm();

    // `artisan` does this in Application::handleCommand(); tests have to opt in.
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    Artisan::call('env');

    $spans = consoleExported($tracer, $exporter);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->getName())->toBe('artisan env')
        ->and($spans[0]->getAttributes()->get('process.exit.code'))->toBe(0);
});
