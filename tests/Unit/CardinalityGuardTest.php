<?php

declare(strict_types=1);

use Fledge\Apm\Export\ThrottledLogWriter;
use Fledge\Apm\Metrics\CardinalityGuard;
use Psr\Log\AbstractLogger;

/**
 * A PSR logger that keeps its lines, behind the real ThrottledLogWriter.
 *
 * @return array{ThrottledLogWriter, ArrayObject<int, string>}
 */
function guardLogWriter(): array
{
    $lines = new ArrayObject;
    $logger = new class($lines) extends AbstractLogger
    {
        /** @param ArrayObject<int, string> $lines */
        public function __construct(private readonly ArrayObject $lines) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines->append($level.' '.$message);
        }
    };

    return [new ThrottledLogWriter(static fn () => $logger), $lines];
}

it('passes attribute sets up to the limit and maps the next one to the overflow set', function () {
    $guard = new CardinalityGuard(3);

    foreach (['a', 'b', 'c'] as $host) {
        expect($guard->attributes('http.client.request.duration', ['server.address' => $host]))
            ->toBe(['server.address' => $host]);
    }

    expect($guard->attributes('http.client.request.duration', ['server.address' => 'd']))
        ->toBe(['otel.metric.overflow' => true])
        ->and($guard->attributes('http.client.request.duration', ['server.address' => 'b']))
        ->toBe(['server.address' => 'b'])
        ->and($guard->count('http.client.request.duration'))->toBe(3);
});

it('counts sets per instrument, independent of key order and distinguishing value types', function () {
    $guard = new CardinalityGuard(2);

    $guard->attributes('one', ['a' => 1, 'b' => 'x']);
    $guard->attributes('one', ['b' => 'x', 'a' => 1]);

    expect($guard->count('one'))->toBe(1);

    $guard->attributes('one', ['a' => '1', 'b' => 'x']);

    expect($guard->count('one'))->toBe(2)
        ->and($guard->attributes('one', ['a' => true, 'b' => 'x']))->toBe(CardinalityGuard::OVERFLOW)
        ->and($guard->attributes('two', ['a' => true, 'b' => 'x']))->toBe(['a' => true, 'b' => 'x'])
        ->and($guard->attributes('two', []))->toBe([]);
});

it('logs the first overflow per instrument once', function () {
    [$writer, $lines] = guardLogWriter();
    $guard = new CardinalityGuard(1, $writer);

    $guard->attributes('fledge.queue.job.wait_time', ['laravel.job.name' => 'A']);
    $guard->attributes('fledge.queue.job.wait_time', ['laravel.job.name' => 'B']);
    $guard->attributes('fledge.queue.job.wait_time', ['laravel.job.name' => 'C']);
    $guard->reset();
    $guard->attributes('fledge.queue.job.wait_time', ['laravel.job.name' => 'D']);
    $guard->attributes('fledge.queue.job.wait_time', ['laravel.job.name' => 'E']);

    expect($lines->getArrayCopy())->toHaveCount(1)
        ->and($lines[0])->toContain('fledge.queue.job.wait_time')
        ->and($lines[0])->toContain('otel.metric.overflow');
});

it('starts counting again after a reset', function () {
    $guard = new CardinalityGuard(1);

    $guard->attributes('x', ['k' => 'a']);

    expect($guard->attributes('x', ['k' => 'b']))->toBe(CardinalityGuard::OVERFLOW);

    $guard->reset();

    expect($guard->attributes('x', ['k' => 'b']))->toBe(['k' => 'b'])
        ->and($guard->count('x'))->toBe(1);
});
