<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fiber;
use Fledge\Apm\ActiveSpan;
use Fledge\Apm\Tracer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\SpanKind;
use stdClass;
use Symfony\Component\Console\Input\InputInterface;
use WeakMap;
use WeakReference;

/**
 * One transaction per artisan command: CommandStarting starts it,
 * CommandFinished ends it with the exit code.
 *
 * Long-running or noisy commands are skipped: `apm.console.ignore` takes
 * command names and `*` patterns and replaces the defaults below. A command
 * run inside a request, job or another command nests under that span.
 *
 * Symfony dispatches CommandFinished also when the command throws. A span
 * whose CommandFinished never arrived anyway (an earlier CommandFinished
 * listener threw, events rerouted only half way) would stay the current span
 * and swallow every later trace, so it is ended:
 * - by the next CommandStarting in the same fiber, once the command's input
 *   object is gone (the command returned or threw and nothing holds it)
 * - by the CommandFinished of an outer command (everything nested above it)
 * - on Terminating, at the latest
 */
final class ConsoleInstrumentation implements Instrumentation
{
    /**
     * Commands that never finish (a single span for hours of work is noise,
     * the job transactions inside carry the signal) or that are not worth a
     * trace.
     *
     * @var list<string>
     */
    public const array DEFAULT_IGNORE = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:*',
        'pulse:work',
        'pulse:check',
        'schedule:work',
        'reverb:start',
        'octane:*',
        'serve',
        'tinker',
        'list',
        'help',
        '_complete',
        'completion',
    ];

    /**
     * Open commands per fiber (or the main sentinel). Per fiber because a
     * span's scope belongs to the fiber that started it.
     *
     * @var WeakMap<object, ConsoleCommandStack>
     */
    private WeakMap $stacks;

    private object $main;

    /** @var list<string> */
    private array $ignore = self::DEFAULT_IGNORE;

    private ?Tracer $tracer = null;

    public function __construct()
    {
        $this->stacks = new WeakMap;
        $this->main = new stdClass;
    }

    public function register(Application $app, Tracer $tracer): void
    {
        $this->tracer = $tracer;

        $configured = $app['config']->get('apm.console.ignore');

        if (is_array($configured)) {
            $this->ignore = array_values(array_filter($configured, is_string(...)));
        }

        $events = $app->make('events');
        $events->listen(CommandStarting::class, $this->start(...));
        $events->listen(CommandFinished::class, $this->finish(...));
        $events->listen(Terminating::class, $this->terminate(...));
    }

    private function start(CommandStarting $event): void
    {
        $stack = $this->stack();
        $stack->abandonFinished();

        $command = $event->command;

        if ($command === '' || $this->ignored($command) || $stack->indexOf($event->input) !== null) {
            return;
        }

        $stack->push($event->input, $this->tracer->startSpan(
            "artisan {$command}",
            SpanKind::KIND_INTERNAL,
            ['laravel.console.command' => $command],
        ));
    }

    private function finish(CommandFinished $event): void
    {
        $active = $this->stack()->pop($event->input);

        if ($active === null) {
            return;
        }

        try {
            $active->setAttribute('process.exit.code', $event->exitCode);

            if ($event->exitCode !== 0) {
                $active->setError("Command exited with code {$event->exitCode}");
            }
        } finally {
            $active->end();
        }
    }

    private function terminate(): void
    {
        $this->stack()->abandonFrom(0);
    }

    private function stack(): ConsoleCommandStack
    {
        $key = Fiber::getCurrent() ?? $this->main;

        return $this->stacks[$key] ??= new ConsoleCommandStack;
    }

    private function ignored(string $command): bool
    {
        return array_any($this->ignore, static fn (string $pattern): bool => Str::is($pattern, $command));
    }
}

/**
 * The open command spans of one fiber, innermost last.
 *
 * @internal
 */
final class ConsoleCommandStack
{
    /** @var list<array{WeakReference<InputInterface>, ActiveSpan}> */
    private array $open = [];

    public function push(InputInterface $input, ActiveSpan $span): void
    {
        $this->open[] = [WeakReference::create($input), $span];
    }

    /**
     * Remove the command's span (and end the nested ones above it that never
     * saw their own finish). Null when the command is not open.
     */
    public function pop(InputInterface $input): ?ActiveSpan
    {
        $index = $this->indexOf($input);

        if ($index === null) {
            return null;
        }

        $this->abandonFrom($index + 1);

        return array_pop($this->open)[1];
    }

    public function indexOf(InputInterface $input): ?int
    {
        foreach ($this->open as $index => [$reference]) {
            if ($reference->get() === $input) {
                return $index;
            }
        }

        return null;
    }

    /**
     * End the commands whose input is gone: they returned or threw without a
     * CommandFinished. Everything nested above the outermost one goes too.
     */
    public function abandonFinished(): void
    {
        foreach ($this->open as $index => [$reference]) {
            if ($reference->get() === null) {
                $this->abandonFrom($index);

                return;
            }
        }
    }

    /**
     * End the spans from `$index` up, innermost first, so every scope is
     * detached in the order it was attached.
     */
    public function abandonFrom(int $index): void
    {
        while (count($this->open) > $index) {
            [, $active] = array_pop($this->open);

            $active->end();
        }
    }
}
