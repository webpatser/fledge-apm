<?php

declare(strict_types=1);

use Fledge\Apm\Instrumentation\ExceptionInstrumentation;
use Fledge\Apm\Instrumentation\Support\ExceptionRecorder;
use Fledge\Apm\Tracer;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

beforeEach(function () {
    // Reported exceptions must not write to a real log file.
    config()->set('logging.default', 'null');
});

/**
 * @param  array<string, mixed>  $config
 * @return array{Tracer, InMemoryExporter}
 */
function exceptionTracer(array $config = []): array
{
    [$tracer, $exporter] = memoryTracer($config);

    (new ExceptionInstrumentation)->register(app(), $tracer);

    return [$tracer, $exporter];
}

/**
 * @return array<string, SpanDataInterface>
 */
function exceptionSpansByName(Tracer $tracer, InMemoryExporter $exporter): array
{
    $tracer->flush();

    return collect($exporter->getSpans())
        ->keyBy(fn (SpanDataInterface $span) => $span->getName())
        ->all();
}

it('records a reported exception on the active span and marks the root failed', function () {
    [$tracer, $exporter] = exceptionTracer();

    $root = $tracer->startSpan('GET /boom', SpanKind::KIND_SERVER);
    $work = $tracer->startSpan('work');

    report(new RuntimeException('boom'));

    $work->end();
    $root->end();

    $spans = exceptionSpansByName($tracer, $exporter);

    expect($spans)->toHaveCount(2);

    $events = $spans['work']->getEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->getName())->toBe('exception')
        ->and($events[0]->getAttributes()->get('exception.type'))->toBe(RuntimeException::class)
        ->and($events[0]->getAttributes()->get('exception.message'))->toBe('boom')
        ->and($events[0]->getAttributes()->get('exception.stacktrace'))->toBeString()->toContain('RuntimeException')
        ->and($spans['work']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans['GET /boom']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans['GET /boom']->getStatus()->getDescription())->toBe('boom');
});

it('keeps a trace the head sampler dropped once an exception was reported', function () {
    [$tracer, $exporter] = exceptionTracer(['sample_rate' => 0.0]);

    $root = $tracer->startSpan('GET /boom', SpanKind::KIND_SERVER);
    $child = $tracer->startSpan('work');

    report(new RuntimeException('boom'));

    $child->end();
    $root->end();

    expect(exceptionSpansByName($tracer, $exporter))->toHaveCount(2);
});

it('drops the same unsampled trace when nothing was reported', function () {
    [$tracer, $exporter] = exceptionTracer(['sample_rate' => 0.0]);

    $root = $tracer->startSpan('GET /fine', SpanKind::KIND_SERVER);
    $tracer->startSpan('work')->end();
    $root->end();

    expect(exceptionSpansByName($tracer, $exporter))->toBeEmpty();
});

it('records an exception reported twice only once', function () {
    [$tracer, $exporter] = exceptionTracer();

    $root = $tracer->startSpan('GET /twice', SpanKind::KIND_SERVER);
    $exception = new RuntimeException('twice');

    report($exception);
    report($exception);

    $root->end();

    $spans = exceptionSpansByName($tracer, $exporter);

    expect($spans['GET /twice']->getEvents())->toHaveCount(1);
});

function failedQuery(): QueryException
{
    return new QueryException(
        'mysql',
        'select * from users where email = ? and pin = ?',
        ['jane@example.test', 4321],
        new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'app.users' doesn't exist"),
    );
}

it('records a QueryException without its bindings by default', function () {
    [$tracer, $exporter] = exceptionTracer();

    $root = $tracer->startSpan('GET /users', SpanKind::KIND_SERVER);

    report(failedQuery());

    $root->end();

    $span = exceptionSpansByName($tracer, $exporter)['GET /users'];
    $attributes = $span->getEvents()[0]->getAttributes();
    $expected = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'app.users' doesn't exist (Connection: mysql, SQL: select * from users where email = ? and pin = ?)";

    expect($attributes->get('exception.message'))->toBe($expected)
        ->and($attributes->get('exception.stacktrace'))->toContain($expected)
        ->and($attributes->get('exception.type'))->toBe(QueryException::class)
        ->and($span->getStatus()->getDescription())->toBe($expected);

    foreach ([$attributes->get('exception.message'), $attributes->get('exception.stacktrace'), $span->getStatus()->getDescription()] as $value) {
        expect($value)->not->toContain('jane@example.test')->not->toContain('4321');
    }
});

it('records the QueryException message with bindings when record_bindings is on', function () {
    config()->set('apm.options.database.record_bindings', true);

    [$tracer, $exporter] = exceptionTracer();

    $root = $tracer->startSpan('GET /users', SpanKind::KIND_SERVER);
    $exception = failedQuery();

    report($exception);

    $root->end();

    $attributes = exceptionSpansByName($tracer, $exporter)['GET /users']->getEvents()[0]->getAttributes();

    expect($attributes->get('exception.message'))->toBe($exception->getMessage())
        ->and($attributes->get('exception.message'))->toContain('jane@example.test');
});

it('only sets the status when another instrumentation already recorded the exception', function () {
    [$tracer, $exporter] = exceptionTracer();

    $root = $tracer->startSpan('GET /boom', SpanKind::KIND_SERVER);
    $work = $tracer->startSpan('work');
    $exception = new RuntimeException('boom');

    ExceptionRecorder::record($work->span, $exception);
    report($exception);

    $work->end();
    $root->end();

    $spans = exceptionSpansByName($tracer, $exporter);

    expect($spans['work']->getEvents())->toHaveCount(1)
        ->and($spans['work']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans['GET /boom']->getEvents())->toBeEmpty()
        ->and($spans['GET /boom']->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
});

it('does not record exceptions the handler does not report', function () {
    [$tracer, $exporter] = exceptionTracer();

    $root = $tracer->startSpan('POST /form', SpanKind::KIND_SERVER);

    report(ValidationException::withMessages(['email' => 'required']));

    $root->end();

    $spans = exceptionSpansByName($tracer, $exporter);

    expect($spans['POST /form']->getEvents())->toBeEmpty()
        ->and($spans['POST /form']->getStatus()->getCode())->not->toBe(StatusCode::STATUS_ERROR);
});

it('ignores an exception reported with no active span', function () {
    [$tracer, $exporter] = exceptionTracer();

    report(new RuntimeException('nobody home'));

    expect(exceptionSpansByName($tracer, $exporter))->toBeEmpty();
});

it('registers nothing for a disabled tracer', function () {
    (new ExceptionInstrumentation)->register(app(), Tracer::disabled());

    report(new RuntimeException('disabled'));

    expect(true)->toBeTrue();
});
