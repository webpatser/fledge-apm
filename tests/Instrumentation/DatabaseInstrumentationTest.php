<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Context\Propagation;
use Fledge\Apm\Instrumentation\DatabaseInstrumentation;
use Fledge\Apm\Tracer;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

/**
 * A memory tracer with the database instrumentation registered on the test app.
 *
 * @return array{Tracer, InMemoryExporter, FiberContextStorage}
 */
function databaseTracer(): array
{
    $result = memoryTracer();

    (new DatabaseInstrumentation)->register(app(), $result[0]);

    return $result;
}

/**
 * @return list<SpanDataInterface>
 */
function databaseSpans(Tracer $tracer, InMemoryExporter $exporter, string $name): array
{
    $tracer->flush();

    return array_values(array_filter(
        $exporter->getSpans(),
        static fn (SpanDataInterface $span): bool => $span->getName() === $name,
    ));
}

it('records a CLIENT span per query with semconv attributes and no inlined bindings', function () {
    [$tracer, $exporter] = databaseTracer();

    DB::statement('create table widgets (id integer primary key, name text)');

    $parent = $tracer->startSpan('GET /widgets', SpanKind::KIND_SERVER);
    DB::table('widgets')->where('id', 987654)->get();
    $parent->end();

    [$span] = databaseSpans($tracer, $exporter, 'SELECT widgets');
    $attributes = $span->getAttributes();

    expect($span->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($span->getParentSpanId())->toBe($parent->span->getContext()->getSpanId())
        ->and($span->getTraceId())->toBe($parent->span->getContext()->getTraceId())
        ->and($attributes->get('db.system.name'))->toBe('sqlite')
        ->and($attributes->get('db.operation.name'))->toBe('SELECT')
        ->and($attributes->get('db.collection.name'))->toBe('widgets')
        ->and($attributes->get('db.query.text'))->toBe('select * from "widgets" where "id" = ?')
        ->and($attributes->get('db.query.text'))->not->toContain('987654')
        ->and($attributes->get('db.namespace'))->not->toBeNull();
});

it('records nothing for queries outside a trace', function () {
    [$tracer, $exporter] = databaseTracer();

    DB::select('select 1');

    $tracer->flush();

    expect($exporter->getSpans())->toBe([]);
});

it('back-dates the span start by the query time', function () {
    [$tracer, $exporter] = databaseTracer();

    $parent = $tracer->startSpan('job');
    event(new QueryExecuted('select * from users where id = ?', [1], 25.0, DB::connection()));
    $parent->end();

    [$span] = databaseSpans($tracer, $exporter, 'SELECT users');
    $duration = $span->getEndEpochNanos() - $span->getStartEpochNanos();

    expect($duration)->toBeGreaterThanOrEqual(25_000_000)
        ->and($duration)->toBeLessThan(500_000_000);
});

it('maps a fledge native connection to mariadb and records the server', function () {
    [$tracer, $exporter] = databaseTracer();

    $connection = new Connection(static fn () => null, 'ikregeer', '', [
        'driver' => 'fledge-mariadb-native',
        'host' => 'db.test',
        'port' => '3307',
    ]);

    $parent = $tracer->startSpan('GET /');
    event(new QueryExecuted('insert into `pulse_entries` (`key`) values (?)', ['x'], 1.5, $connection));
    $parent->end();

    [$span] = databaseSpans($tracer, $exporter, 'INSERT pulse_entries');
    $attributes = $span->getAttributes();

    expect($attributes->get('db.system.name'))->toBe('mariadb')
        ->and($attributes->get('db.namespace'))->toBe('ikregeer')
        ->and($attributes->get('server.address'))->toBe('db.test')
        ->and($attributes->get('server.port'))->toBe(3307)
        ->and($attributes->get('db.collection.name'))->toBe('pulse_entries');
});

it('maps connection drivers to db.system.name', function (string $driver, string $system) {
    expect(DatabaseInstrumentation::system($driver))->toBe($system);
})->with([
    ['mysql', 'mysql'],
    ['fledge-mysql', 'mysql'],
    ['fledge-mysql-native', 'mysql'],
    ['mariadb', 'mariadb'],
    ['fledge-mariadb', 'mariadb'],
    ['fledge-mariadb-native', 'mariadb'],
    ['pgsql', 'postgresql'],
    ['fledge-pgsql', 'postgresql'],
    ['sqlite', 'sqlite'],
    ['sqlsrv', 'microsoft.sql_server'],
    ['libsql', 'libsql'],
    ['', 'other_sql'],
]);

it('derives operation and collection best effort', function (string $sql, ?string $operation, ?string $collection, string $name) {
    $parsed = DatabaseInstrumentation::operation($sql);

    expect($parsed)->toBe($operation)
        ->and(DatabaseInstrumentation::collection($sql, $parsed))->toBe($collection)
        ->and(DatabaseInstrumentation::spanName($parsed, DatabaseInstrumentation::collection($sql, $parsed), null))->toBe($name);
})->with([
    'select' => ['select * from `pulse_entries` where `type` = ?', 'SELECT', 'pulse_entries', 'SELECT pulse_entries'],
    'insert' => ['insert into "widgets" ("name") values (?)', 'INSERT', 'widgets', 'INSERT widgets'],
    'update' => ['update `widgets` set `name` = ? where `id` = ?', 'UPDATE', 'widgets', 'UPDATE widgets'],
    'delete' => ['DELETE FROM widgets WHERE id = ?', 'DELETE', 'widgets', 'DELETE widgets'],
    'schema qualified' => ['select * from `app`.`widgets`', 'SELECT', 'widgets', 'SELECT widgets'],
    'leading comment' => ['/* tag */ select * from widgets', 'SELECT', 'widgets', 'SELECT widgets'],
    'join has no single collection' => ['select * from a join b on a.id = b.a_id', 'SELECT', null, 'SELECT'],
    'no table' => ['select 1', 'SELECT', null, 'SELECT'],
    'transaction' => ['begin', 'BEGIN', null, 'BEGIN'],
]);

it('caps the statement length on a UTF-8 boundary', function () {
    config(['apm.options.database.max_statement_length' => 8]);

    [$tracer, $exporter] = databaseTracer();

    $parent = $tracer->startSpan('GET /');
    event(new QueryExecuted('select éééé from widgets', [], 1.0, DB::connection()));
    $parent->end();

    [$span] = databaseSpans($tracer, $exporter, 'SELECT widgets');
    $text = $span->getAttributes()->get('db.query.text');

    // 'select ' is 7 bytes; the 2-byte é does not fit in the 8th, so it is dropped whole.
    expect($text)->toBe('select ')
        ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
});

it('sets legacy db.system and db.statement by default and drops them on request', function (bool $legacy) {
    config(['apm.options.database.legacy_attributes' => $legacy]);

    [$tracer, $exporter] = databaseTracer();

    $parent = $tracer->startSpan('GET /');
    event(new QueryExecuted('select * from widgets', [], 1.0, DB::connection()));
    $parent->end();

    [$span] = databaseSpans($tracer, $exporter, 'SELECT widgets');
    $attributes = $span->getAttributes();

    expect($attributes->get('db.system'))->toBe($legacy ? 'sqlite' : null)
        ->and($attributes->get('db.statement'))->toBe($legacy ? 'select * from widgets' : null)
        ->and($attributes->get('db.system.name'))->toBe('sqlite');
})->with([true, false]);

it('lands queries of parallel fibers under the span of their own fiber', function () {
    [$tracer, $exporter, $storage] = databaseTracer();
    $propagation = new Propagation($storage);

    DB::statement('create table widgets (id integer primary key, name text)');

    $parent = $tracer->startSpan('GET /pulse', SpanKind::KIND_SERVER);
    $futures = [];

    foreach ([1, 2] as $card) {
        $futures[$card] = async($propagation->wrap(function () use ($tracer, $card): string {
            return $tracer->span("card {$card}", function ($span) use ($card): string {
                DB::select("select * from widgets where id = ? /* card {$card} first */", [$card]);

                // The other card runs while this one is suspended inside its span.
                delay(0.005 * (3 - $card));

                DB::select("select * from widgets where id = ? /* card {$card} second */", [$card]);

                return $span->span->getContext()->getSpanId();
            });
        }));
    }

    $cardSpanIds = await($futures);
    $parent->end();

    $tracer->flush();

    $queries = array_values(array_filter(
        $exporter->getSpans(),
        static fn (SpanDataInterface $span): bool => $span->getName() === 'SELECT widgets',
    ));

    expect($queries)->toHaveCount(4)
        ->and($cardSpanIds[1])->not->toBe($cardSpanIds[2]);

    foreach ($queries as $query) {
        $text = $query->getAttributes()->get('db.query.text');
        $card = str_contains($text, 'card 1') ? 1 : 2;

        expect($text)->toContain("card {$card}")
            ->and($query->getParentSpanId())->toBe($cardSpanIds[$card])
            ->and($query->getTraceId())->toBe($parent->span->getContext()->getTraceId());
    }
});
