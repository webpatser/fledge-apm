# Fledge APM

Fiber-aware OpenTelemetry tracing for [Fledge](https://github.com/webpatser/fledge) apps, exported as OTLP to Elastic APM or any OTel backend.

Fledge runs work in parallel fibers: Livewire lazy cards, `FiberDB::concurrent`, anything you start with `async()`. Stock OTel context storage cannot follow that, so parallel work either bleeds into the wrong span or starts a trace of its own. Fledge APM keeps one active span per fiber, and a fiber started with `async()` inherits the span of whoever started it. A request with five parallel cards shows up as one trace with five sibling spans, and the queries nest under the card that ran them.

It is independent of Laravel Pulse. Pulse stays your at-a-glance dashboard; this gives you the per-request span trees.

## Requirements

- PHP 8.6
- `webpatser/fledge-framework` ^13.43.0.3
- `webpatser/fledge-fiber` ^13.43.0.3 (required: provides `Fledge\Async\FiberContext`, which lets parallel fibers join the current trace). Without the hook the package logs a warning (at most once an hour).

### PHP version policy

This package supports only the latest stable PHP release. When a new PHP version is released, the next release of this package requires it and support for the previous PHP version ends. Currently: PHP 8.6.

## Install

```bash
composer require webpatser/fledge-apm
```

The OTel SDK discovers its pieces through two Composer plugins, so your app has to allow them:

```json
{
    "config": {
        "allow-plugins": {
            "php-http/discovery": true,
            "tbachert/spi": true
        }
    }
}
```

The service provider is auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=apm-config
```

## Configuration

Tracing is off until you turn it on. When disabled, no listeners are registered and the tracer is a no-op, so the cost is zero.

```dotenv
APM_ENABLED=true
APM_SAMPLE_RATE=1.0

OTEL_SERVICE_NAME=my-app
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer%20<secret-token>"
```

| Variable | Default | What it does |
|---|---|---|
| `APM_ENABLED` | `false` | Master switch. |
| `APM_SAMPLE_RATE` | `1.0` | Share of new traces to keep (0.0 to 1.0). |
| `APM_KEEP_ERRORS` | `true` | Keep traces with a failed span even when the ratio dropped them. |
| `APM_SLOW_THRESHOLD_MS` | `1000` | Keep traces whose transaction took at least this long. `0` disables. |
| `OTEL_SERVICE_NAME` | `APP_NAME` | Service name in the APM UI. |
| `OTEL_DEPLOYMENT_ENVIRONMENT` | `APP_ENV` | Environment label. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Base URL; `/v1/traces` is appended. |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` | none | Full traces URL, wins over the base endpoint. |
| `OTEL_EXPORTER_OTLP_HEADERS` | none | Comma separated `key=value` pairs, values URL encoded. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | Or `http/json`. gRPC is not supported. |
| `OTEL_EXPORTER_OTLP_COMPRESSION` | `gzip` | Or `none`. |
| `OTEL_EXPORTER_OTLP_TIMEOUT` | `2000` | Per request, in milliseconds. |

The `http/protobuf` encoder is pure PHP, so `ext-protobuf` is optional (it only makes encoding faster).

For Elastic, pass credentials in the headers. A secret token:

```dotenv
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer%20<secret-token>"
```

An API key:

```dotenv
OTEL_EXPORTER_OTLP_HEADERS="Authorization=ApiKey%20<base64 id:key>"
```

The space in the header value is encoded as `%20`. See `config/apm.php` for batching, breaker and per-instrumentation options.

## Production safety

A tracer must never take your app down with it. Spans are buffered in process and exported after the response was sent (terminating callback, off the response path). Queue workers flush on an interval and once more when they stop. Under Octane, spans are exported once `APM_MAX_EXPORT_BATCH_SIZE` spans are buffered or `APM_WORKER_FLUSH_INTERVAL` seconds (default 5) have passed, checked when a request ends, and when the worker stops. Where the Fledge event loop runs (the Fledge server, torque workers), a background timer also exports an idle worker's spans every `APM_WORKER_FLUSH_INTERVAL` seconds. Under Swoole, RoadRunner and FrankenPHP no loop runs between requests, so an idle worker keeps its spans until its next request or until it stops. A worker killed without a clean stop (`kill -9`, a reload past its kill timeout) loses what was buffered since its last export: up to `APM_MAX_EXPORT_BATCH_SIZE` minus one spans plus the last request's spans, capped by `APM_MAX_QUEUE_SIZE`. A request that produced no spans (not sampled, or APM disabled) does not start a background flush.

- **Flush budget:** `APM_FLUSH_BUDGET_MS` (default 1500) bounds one whole flush, including timeouts and retries. A slow or dead intake holds a PHP-FPM or queue worker for at most that long, and a flush stops at its first failed chunk.
- **No retries on hot paths:** retries only happen on the final flush (queue `WorkerStopping`, shutdown). After an HTTP response or between jobs it is one attempt per chunk.
- **Circuit breaker:** after a failed export, every worker skips exporting for `APM_BREAKER_SECONDS` (default 30) and drops its spans. The state lives in APCu when enabled, else your default cache store, else per process. "Export failure" log lines are throttled to one per window. Running out of the flush budget partway through a flush, after the intake accepted an earlier chunk, does not open the breaker (the rest of the batch is dropped). A first chunk that exhausts the budget, a failure that returns before the deadline (a 5xx or connect error), a request that runs past the flush deadline, and 3 budget cut-offs in a row on one worker still open it; a flush that exports everything resets that count.
- **Bounded memory:** the queue holds at most `APM_MAX_QUEUE_SIZE` spans (default 2048) and exports in chunks of `APM_MAX_EXPORT_BATCH_SIZE` (default 128).

The safest setup is a collector on localhost: the app posts to `localhost:4318` in a millisecond, and the collector (or EDOT agent) handles auth, retries and the trip to Elastic.

## What gets traced

Each source can be switched off under `apm.instrumentation`.

| Source | Recorded as |
|---|---|
| HTTP kernel | Root transaction: route name, method, status code, user id. Back-dated to request start. |
| Queue jobs | Consumer transaction per job. The `traceparent` travels in the job payload, so a job continues the trace that dispatched it. Each dispatch inside a trace gets a PRODUCER span `send {queue}` that parents the consumer. The first attempt carries `fledge.queue.wait_ms` (a `later()` delay is included; a `queue:retry` resets attempts, so the retried job's wait time counts from the original dispatch). Producer spans that never see `JobQueued` (database `bulk()`, bus batches on the database driver, or a push that threw) are ended at a later sweep (Looping or the terminating callback, at most 60 s for spans of a fiber still running) and carry `fledge.queue.unconfirmed`, so their duration is not the push time. |
| Artisan commands | Command transaction. Long-running or noisy commands (`queue:work`, `horizon:*`, `pulse:*`, `schedule:work`, `torque:start`, `torque:monitor`, `torque:tail`, `resonate:start`, `octane:*`, `serve`, `tinker` and friends) are skipped; see `apm.console.ignore`. |
| Database queries | DB span, back-dated by the query time. Statements are truncated at 2048 characters and bindings are never inlined. |
| Cache | Cache spans for get, put and forget. Keys are not recorded by default. |
| HTTP client | Client span per outgoing request of Laravel's `Http` client, with a `traceparent` header sent along. URLs are redacted. Spans also carry `network.peer.address`, `network.peer.port`, `network.protocol.version`, `http.response.body.size`, `fledge.http.connect_ms`, `fledge.http.tls_ms`, `fledge.http.ttfb_ms` and `fledge.http.connection_reused` (reuse and ALPN need a fledge-fiber release that reports them; without ALPN the protocol version comes from the response). `fledge.http.connect_ms` and `fledge.http.tls_ms` are omitted on a fledge-fiber release that does not report `connection_reused`, and on a failed connection. |
| Livewire | A `livewire update` span per update request, with mount, update, hydrate, properties, call and render spans per component below it. For `FiberHandleRequests` batches each lazy card is a sibling span. |
| Exceptions | Reported exceptions are recorded on the active span and mark the transaction as failed, which also makes the trace sampler keep it. |

Spans are only created inside a trace, so a query run by something that has no transaction (a boot-time call, say) is skipped instead of creating orphan traces.

## Metrics

Next to the traces the package can export OTLP metrics. They are off until you set `APM_METRICS_ENABLED=true` (APM itself has to be on too) and go to the same intake as the traces through their own transport instance, which shares the process-wide keep-alive connection pool with traces, behind the same circuit breaker. The endpoint is `OTEL_EXPORTER_OTLP_ENDPOINT` plus `/v1/metrics`, or `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` as is. Headers, protocol, compression and timeouts are shared with the traces. A metrics export has no retries: the next interval carries fresh deltas.

| Setting | Env var | Default | What it does |
|---|---|---|---|
| `apm.metrics.enabled` | `APM_METRICS_ENABLED` | `false` | Master switch for metrics. |
| `apm.metrics.interval` | `APM_METRICS_INTERVAL` | `60` | Seconds between exports in a long-running process. |
| `apm.metrics.temporality` | none | `delta` | `delta`, `cumulative` or `lowmemory`. |
| `apm.metrics.cardinality_limit` | none | `200` | Distinct attribute sets per instrument per interval. |
| `apm.metrics.fpm` | `APM_METRICS_FPM` | `false` | Record metrics under per-request (non-CLI) SAPIs such as PHP-FPM and export each request's metrics. |
| `apm.metrics.groups.http_client` | none | `true` | HTTP client instruments. |
| `apm.metrics.groups.queue` | none | `true` | Job instruments. |
| `apm.metrics.groups.queue_backlog` | none | `true` | Queue backlog gauges (see below). |
| `apm.metrics.groups.runtime` | none | `true` | PHP memory and garbage collector instruments (see Runtime, loop and pool). |
| `apm.metrics.groups.loop` | none | `true` | Event loop lag and watcher instruments; need a running event loop. |
| `apm.metrics.groups.database_pool` | none | `true` | Database connection pool instruments; need a fledge-fiber release with the pool registry. |
| `apm.metrics.queue_backlog.queues` | none | `[]` | Connection name to queue names whose backlog is reported. Empty reports nothing. |
| `apm.metrics.queue_backlog.lock_store` | none | `null` | Cache store for the lock that picks the one reporting process; `null` for the default store. |
| `apm.otlp.metrics_endpoint` | `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | none | Full metrics URL, wins over the base endpoint. |

Process model. A long-running process (a queue worker, an Octane worker, a daemon marked with `Metrics::markLongRunning()`, or a CLI process that runs its work inside fibers of the Fledge event loop and is still alive after one interval) exports every `APM_METRICS_INTERVAL` seconds and once more when it stops. A short artisan command exports nothing and never waits at exit. An interval without a single data point sends nothing.

Other packages record through `Fledge\Apm\Metrics\Metrics`, whose static methods are no-ops while metrics are off: `meter($scope)`, `enabled()`, `cardinality()`, `markLongRunning($role)` and `collectDue()`.

- `markLongRunning($role)` marks a CLI daemon long-running and sets `fledge.process.role`. The interval runs on an unreferenced event loop timer: called inside a fiber of the running Fledge event loop it starts at once; called from outside any fiber (before `Loop::run()`, or between awaits) it arms on the next loop turn. A stock `queue:work` gets no timer and exports on `Looping` instead: once per interval it waits for the export round trip before its next pop, bounded by `OTEL_EXPORTER_OTLP_TIMEOUT`. A process that never runs a loop gets no timer, and nothing keeps a loop alive or makes the process wait at exit.
- `collectDue(): bool` is for a long-running process without a continuously running event loop, which drives the interval itself (torque's master calls it every maintenance tick, about once a second). It never throws. It returns false and does nothing unless metrics record, the process is long-running, no collect is in flight and the interval has elapsed since the last collect. Otherwise it collects, waits until the export finished (from outside a loop the wait drives the event loop until then; inside a fiber it suspends only that fiber), bounded by the OTLP request timeouts, and returns whether the export succeeded. The first call opts the process out of the loop timer, due or not: a running or queued timer is cancelled and none starts afterwards, so a loop that only turns briefly never runs background exports. Such a process reports neither the queue backlog nor loop metrics, whichever of `markLongRunning()` and `collectDue()` it calls first; memory, garbage collector and pool gauges still go out.

`APM_METRICS_FPM` covers per-request (non-CLI) SAPIs such as PHP-FPM. With it off nothing is recorded there; with it on each request's values go out after the response was sent. Each such request builds its own meter provider; the resource detectors it shares with the tracer run once per request. Octane on FrankenPHP is not a CLI process either, so it needs `APM_METRICS_FPM` to record; its first request then marks the worker long-running, and it exports per interval instead of per request: at the end of the first request after the interval passed, or when the worker stops. An idle Octane worker therefore exports nothing until its next request.

Temporality is delta by default: each export carries what happened since the previous one, so a counter is the sum of its documents. Histograms use explicit bucket boundaries in seconds.

Cardinality is capped per instrument: after `apm.metrics.cardinality_limit` distinct attribute sets in one interval, further sets are recorded as `otel.metric.overflow=true` and the first overflow of an instrument is logged once. That bounds the documents Elasticsearch receives per interval.

With metrics enabled, traces and metrics both carry `service.instance.id` and `fledge.process.role`. On traces the role is the SAPI default (`cli`, `fpm` or `web`) and the instance id is the pid of the process that built the tracer; detected and marked roles (for example `queue`, `octane`, or a role a package sets through `Metrics::markLongRunning()`) and the pids of forked children appear on metrics only. APM Server maps `service.instance.id` to `service.node.name`, so Kibana lists one instance per process on metrics. With metrics off, traces carry neither.

| Instrument | Kind | Unit | Attributes |
|---|---|---|---|
| `http.client.request.duration` | histogram, boundaries 0.005 to 10 | `s` | `http.request.method`, `server.address`, `server.port`, `http.response.status_code`, `error.type` |
| `messaging.process.duration` | histogram, boundaries 0.005 to 600 | `s` | `messaging.system`, `messaging.destination.name`, `laravel.queue.connection`, `laravel.job.name`, `error.type` |
| `messaging.client.consumed.messages` | counter | `{message}` | `messaging.system`, `messaging.destination.name`, `laravel.queue.connection`, `laravel.job.name` |
| `fledge.queue.job.wait_time` | histogram, boundaries 0.01 to 3600, first attempt only | `s` | `messaging.system`, `messaging.destination.name`, `laravel.queue.connection`, `laravel.job.name` |
| `fledge.queue.job.retries` | counter | `{job}` | as above, plus `error.type` |
| `fledge.queue.job.failures` | counter | `{job}` | as above, plus `error.type` |

`error.type` is the exception class short name, or the status code for a response the client span marks as an error (5xx, and 4xx with `error_on_4xx`). URLs, paths and job ids are never attributes. The HTTP and job metrics are recorded whether or not the span is sampled, and `options.http_client.timings` does not affect them, but only while `apm.instrumentation.http_client` and `apm.instrumentation.queue` are on. Wait time is measured from the dispatch on the first attempt only, so a `later()` delay is included; sync queue jobs run inside the dispatch and record no wait time metric.

### Queue backlog

List the queues to watch, per queue connection:

```php
// config/apm.php
'metrics' => [
    // ...
    'queue_backlog' => [
        'queues' => [
            'redis' => ['default', 'mail'],
            'database' => ['reports'],
        ],
        'lock_store' => 'redis', // null for the default cache store
    ],
],
```

| Instrument | Kind | Unit | Attributes |
|---|---|---|---|
| `fledge.queue.pending` | gauge | `{job}` | `messaging.system`, `messaging.destination.name`, `laravel.queue.connection` |
| `fledge.queue.delayed` | gauge | `{job}` | as above |
| `fledge.queue.reserved` | gauge | `{job}` | as above |
| `fledge.queue.oldest_age` | gauge | `s` | as above |

Each comes from the queue driver's `pendingSize`, `delayedSize`, `reservedSize` and `creationTimeOfOldestPendingJob` (the same numbers `queue:monitor` uses); `oldest_age` is now minus the creation time of the oldest pending job and is absent, not zero, while nothing is pending. A driver without one of these methods skips that gauge. Nothing is queried while `queues` is empty, with `groups.queue_backlog` off, or in a process that is not long-running (short artisan commands, PHP-FPM, even with `APM_METRICS_FPM`).

At most one process reports. Every long-running process samples once per interval, but only the holder of a cache lock (key `fledge-apm:queue-backlog:{service}:{environment}`, so apps and environments sharing a cache store do not block each other) queries the queues. The holder renews the lock by owner every interval with a TTL of the interval plus half an interval of grace (2 to 30 seconds), so it stays the reporter for as long as it lives. It reports only while its own lease is valid, which ends half the grace (at least one second) before the TTL, because database, DynamoDB and file locks expire on whole seconds. A worker that stops cleanly releases the lock and a standby takes over within one interval; after a holder dies the series has a gap of up to about one TTL plus one interval, until a standby takes the expired lock.

When the values are sampled:

- Stock `queue:work`: on `Looping`, once per interval, so the queries run between jobs and delay the next pop by their duration, as does the metrics export that follows (bounded by `OTEL_EXPORTER_OTLP_TIMEOUT`).
- Event-loop processes (torque workers, the Fledge server, daemons): on an unreferenced timer, each sample in its own fiber. The timer arms inside a fiber of the running loop, or on the first loop turn for a daemon marked before `Loop::run()`. Nothing arms in a stock `queue:work`, not even during the loop turn it takes for its signal watchers before the first `Looping`.
- Octane: from the terminating callback, after the response was sent, once per interval (the first sample too).
- A process that drives its own collects through `Metrics::collectDue()` (torque's master) never samples the queues and never takes the lock.

Samples never run inside the metrics export. After the first failure on a connection its remaining queues are skipped for that sample, so a down database or Redis costs one connect timeout and one throttled log line per interval, plus that connection's points. The numbers are up to one interval old.

Use a lock store all workers share (`redis`, `database`, `memcached`, `dynamodb`; `file` only on a single host). A store without lock support, and the `null` store, report nothing at all and log a warning, because every worker reporting would multiply the series. The `array` store is per process, so with it every worker reports.

### Runtime, loop and pool

Three independent groups, each behind its own toggle, created once the process is detected as long-running (never in a short artisan command or a per-request SAPI).

| Instrument | Kind | Unit | Attributes |
|---|---|---|---|
| `fledge.loop.lag` | histogram, boundaries 0.0005 to 2.5 | `s` | none |
| `fledge.loop.watchers` | gauge | `{watcher}` | `fledge.loop.watcher.type` (`defer`, `delay`, `repeat`, `readable`, `writable`, `signal`) |
| `fledge.php.memory.usage` | gauge | `By` | none |
| `fledge.php.memory.peak` | gauge | `By` | none |
| `fledge.php.gc.runs` | counter | `{run}` | none |
| `fledge.php.gc.collected` | counter | `{object}` | none |
| `fledge.php.gc.duration` | counter | `s` | none |
| `db.client.connection.count` | gauge | `{connection}` | `db.client.connection.pool.name`, `db.client.connection.state` (`idle`, `used`) |
| `db.client.connection.max` | gauge | `{connection}` | `db.client.connection.pool.name` |
| `db.client.connection.pending_requests` | gauge | `{request}` | `db.client.connection.pool.name` |

- Loop (`groups.loop`) records only in a process driven by the Fledge event loop. `fledge.loop.lag` is the drift of an unreferenced one second repeat timer: the moment it fired minus the moment it was due, never below zero. The timer never keeps a loop alive; it arms inside a fiber of the running loop, or on the first loop turn for a daemon marked before `Loop::run()`. A stock `queue:work` (which turns the loop only briefly, from the main context), an Octane worker that receives requests outside any fiber, and a process that drives its own collects through `Metrics::collectDue()` record no loop metrics. `fledge.loop.watchers` counts every registered, non-cancelled event loop callback by type, disabled and unreferenced ones and fledge-apm's own timers included; every type is reported, zero included.
- Runtime (`groups.runtime`) works with or without an event loop. Memory is the size allocated from the system (`memory_get_usage(true)`, `memory_get_peak_usage(true)`). The garbage collector instruments read the cumulative `gc_status()` numbers (`runs`, `collected`, and `collector_time` in seconds, left out on a PHP without it); the SDK exports the change per interval.
- Pools (`groups.database_pool`) come from the fledge-fiber pool registry and need a fledge-fiber release that has it; with an older release these instruments do not exist. Several live pools can share one connection name (read/write split, per-task connections), so the numbers are summed per name; `used` is open connections minus idle ones.

## Privacy defaults

| Option | Default | Notes |
|---|---|---|
| `apm.http.record_client_ip` | `false` | `client.address`. |
| `apm.http.record_user_agent` | `true` | `user_agent.original`. |
| `apm.http.record_user_id` | `true` | `enduser.id`. |
| `apm.options.cache.record_keys` | `false` | Cache keys can hold PII. |
| `apm.options.database.record_bindings` | `false` | Bindings are stripped from recorded exception messages. |
| `apm.options.http_client.record_full_path` | `false` | Keeps the path in `url.full` verbatim; see below. |
| `apm.options.http_client.error_on_4xx` | `false` | Only 5xx and transport errors mark a client span as failed. |
| `apm.options.http_client.timings` | `true` | Connect, TLS and TTFB timings, peer address, protocol version and body size on client spans. |
| `apm.options.queue.producer_spans` | `true` | PRODUCER span per dispatch inside a trace. `fledge.queue.wait_ms` on the consumer span is recorded either way. |

Query bindings are never sent, request bodies and headers are never recorded, and outgoing URLs are redacted.

### Outgoing URL paths

`url.full` on client spans drops userinfo and the fragment, and redacts credential-looking query values. Secrets carried in the path are replaced by `REDACTED` too: Telegram bot tokens (`/botREDACTED/sendMessage`), Slack and Discord webhook secrets, and any segment with a random-looking run of 32 or more characters (JWTs, percent-encoded base64 and segments with matrix params included).

UUIDs, version strings and ordinary file names stay readable. 32+ character hex identifiers such as Git SHAs, MD5 object keys and Gravatar hashes are hidden. Set `apm.options.http_client.record_full_path` to `true` to get the old behaviour back and export the path verbatim; it applies to the whole app, and userinfo and query redaction still apply.

Limits, stated plainly:

- A path secret shorter than 32 characters outside the known Telegram, Slack and Discord shapes is exported as is.
- A wrapper exception that rewords the SQL message instead of including it verbatim still exports what it wrote.
- The database driver's own message is kept, so a message such as `Duplicate entry 'jane@example.test' for key ...` still contains the value.

## Sampling

Head sampling keeps `APM_SAMPLE_RATE` of new traces. Below 1.0, traces the ratio dropped are still recorded in memory, and are exported anyway when a span failed (`keep.errors`) or the transaction was slow (`keep.slow_ms`). For plain head sampling, set `keep.errors` to `false` and `keep.slow_ms` to `0`; dropped traces then cost almost nothing.

An incoming `traceparent` is ignored by default, so a public client cannot choose your trace ids. Set `apm.http.trust_traceparent` to `true` to continue the caller's trace id when requests come from services you own (or a trusted proxy). Even then its sampled flag is not honored by default (`apm.http.honor_sampled_flag`), so an outside client cannot force or suppress sampling on your app. Turn it on if the caller is another service you own and you want one sampling decision across both.

## Known limits

- Under Swoole, RoadRunner and FrankenPHP an idle worker keeps its buffered spans until its next request or until it stops; see Production safety.
- Only Laravel's `Http` client is traced. Other clients (raw Guzzle, curl, SDKs with their own transport) are not.
- While the circuit breaker is open, spans are dropped, not queued.
- Raw `EventLoop::queue()` callbacks and Fledge's low-level server/IO loops do not carry trace context. Fibers started with `async()` do.

## Elastic APM notes

Kibana's APM UI only reads the `traces-apm*`, `metrics-apm*` and `logs-apm.error*` data streams, and those are fed by an APM intake. Writing OTLP at a bare Elasticsearch cluster does nothing. You need one of:

- APM Server
- the Elastic Agent with the APM integration (Fleet)
- an EDOT collector (Elastic Distribution of OpenTelemetry)

Point `OTEL_EXPORTER_OTLP_ENDPOINT` at that intake. Because this is plain OTLP, the same setup works with any other OTel backend (Tempo, Jaeger, Honeycomb, and so on).

## Development

```bash
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan
```

## Origins

Fledge's async layer started as a port of [amphp](https://amphp.org) and its event loop as a port of [Revolt](https://revolt.run), both MIT licensed. Those projects showed how far fibers can take PHP, and Fledge builds on that work. Fledge has since gone its own way: PHP 8.6 only, shaped around Laravel, and maintained independently, so it does not track either project.

## License

MIT. See [LICENSE](LICENSE).
