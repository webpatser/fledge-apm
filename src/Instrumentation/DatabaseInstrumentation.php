<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation;

use Fledge\Apm\Tracer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * One CLIENT span per executed query.
 *
 * QueryExecuted fires after the query ran, in the fiber that ran it, so the
 * span is recorded finished, back-dated by `$event->time`, under that fiber's
 * current span. Parallel fibers (Livewire cards, FiberDB::concurrent) each
 * get their queries under their own parent.
 *
 * Queries outside any trace (no current span, e.g. a boot-time query) are not
 * recorded: they would each become a one-span trace of their own.
 *
 * Options (all under `apm.options.database`):
 * - `max_statement_length` (int, default 2048): `db.query.text` is cut to this many bytes, 0 disables the cap.
 * - `legacy_attributes` (bool, default true): also set `db.system` and `db.statement`, which intakes that predate the stable `db.system.name` / `db.query.text` (Kibana's APM database view) key on.
 *
 * Bindings are never recorded; `db.query.text` keeps the `?` placeholders.
 */
final class DatabaseInstrumentation implements Instrumentation
{
    public const int DEFAULT_MAX_STATEMENT_LENGTH = 2048;

    /**
     * Connection driver name to OpenTelemetry `db.system.name`. Unknown
     * drivers fall back to the driver name itself.
     */
    private const array SYSTEMS = [
        'mysql' => 'mysql',
        'fledge-mysql' => 'mysql',
        'fledge-mysql-native' => 'mysql',
        'mariadb' => 'mariadb',
        'fledge-mariadb' => 'mariadb',
        'fledge-mariadb-native' => 'mariadb',
        'pgsql' => 'postgresql',
        'fledge-pgsql' => 'postgresql',
        'sqlite' => 'sqlite',
        'sqlsrv' => 'microsoft.sql_server',
    ];

    private int $maxStatementLength = self::DEFAULT_MAX_STATEMENT_LENGTH;

    private bool $legacyAttributes = true;

    public function register(Application $app, Tracer $tracer): void
    {
        $config = $app['config'];

        $this->maxStatementLength = max(0, (int) $config->get('apm.options.database.max_statement_length', self::DEFAULT_MAX_STATEMENT_LENGTH));
        $this->legacyAttributes = (bool) $config->get('apm.options.database.legacy_attributes', true);

        $app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($tracer): void {
            $this->record($tracer, $event);
        });
    }

    public function record(Tracer $tracer, QueryExecuted $event): void
    {
        if (! $tracer->currentSpan()->getContext()->isValid()) {
            return;
        }

        $sql = (string) $event->sql;
        $operation = self::operation($sql);
        $collection = self::collection($sql, $operation);
        $system = self::system((string) $event->connection->getDriverName());
        $namespace = $event->connection->getDatabaseName();
        $statement = $this->statement($sql);

        $attributes = [
            'db.system.name' => $system,
            'db.namespace' => $namespace !== '' ? $namespace : null,
            'db.query.text' => $statement,
            'db.operation.name' => $operation,
            'db.collection.name' => $collection,
            'server.address' => self::host($event),
            'server.port' => self::port($event),
        ];

        if ($this->legacyAttributes) {
            $attributes['db.system'] = $system;
            $attributes['db.statement'] = $statement;
        }

        $tracer->recordSpan(
            name: self::spanName($operation, $collection, $namespace),
            startEpochNanos: Tracer::nanosAgo((float) $event->time),
            kind: SpanKind::KIND_CLIENT,
            attributes: array_filter($attributes, static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    /**
     * @return non-empty-string
     */
    public static function system(string $driver): string
    {
        return self::SYSTEMS[$driver] ?? ($driver !== '' ? $driver : 'other_sql');
    }

    /**
     * The statement's leading keyword, upper-cased (`SELECT`, `INSERT`, ...).
     * Best effort: a `WITH` query is reported as `WITH`.
     */
    public static function operation(string $sql): ?string
    {
        if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)*([a-z]+)/is', $sql, $match) !== 1) {
            return null;
        }

        return strtoupper($match[1]);
    }

    /**
     * The target table, only for single-table statements (a join has several,
     * OpenTelemetry then leaves the collection unset). Schema prefix and
     * identifier quoting are stripped.
     */
    public static function collection(string $sql, ?string $operation): ?string
    {
        if (preg_match('/\bjoin\b/i', $sql) === 1) {
            return null;
        }

        $pattern = match ($operation) {
            'SELECT', 'DELETE' => '/\bfrom\s+([`"\[]?[\w$.`"\]\[]+)/i',
            'INSERT', 'REPLACE' => '/\binto\s+([`"\[]?[\w$.`"\]\[]+)/i',
            'UPDATE' => '/^\s*update\s+(?:or\s+\w+\s+)?([`"\[]?[\w$.`"\]\[]+)/i',
            default => null,
        };

        if ($pattern === null || preg_match($pattern, $sql, $match) !== 1) {
            return null;
        }

        $segments = explode('.', str_replace(['`', '"', '[', ']'], '', $match[1]));
        $table = end($segments);

        return $table !== '' ? $table : null;
    }

    /**
     * `SELECT pulse_entries`, falling back to the operation, the database
     * name, or a generic `db`.
     *
     * @return non-empty-string
     */
    public static function spanName(?string $operation, ?string $collection, ?string $namespace): string
    {
        $target = $collection ?? ($namespace !== null && $namespace !== '' ? $namespace : null);

        return match (true) {
            $operation !== null && $target !== null => "{$operation} {$target}",
            $operation !== null => $operation,
            $target !== null => $target,
            default => 'db',
        };
    }

    private function statement(string $sql): string
    {
        if ($this->maxStatementLength === 0 || strlen($sql) <= $this->maxStatementLength) {
            return $sql;
        }

        // mb_strcut cuts on a byte budget without splitting a UTF-8 character.
        return mb_strcut($sql, 0, $this->maxStatementLength, 'UTF-8');
    }

    private static function host(QueryExecuted $event): ?string
    {
        $host = $event->connection->getConfig('host');

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function port(QueryExecuted $event): ?int
    {
        $port = $event->connection->getConfig('port');

        return is_numeric($port) && (int) $port > 0 ? (int) $port : null;
    }
}
