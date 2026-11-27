# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.6.1] - 2026-11-27

### Changed

- `ExportDeadline` uses `spl_object_id()` instead of `spl_object_hash()`, which PHP 8.6 deprecates; behaviour is unchanged.

## [0.6.0] - 2026-11-27

### Added

- Queue backlog gauges `fledge.queue.pending`, `fledge.queue.delayed`, `fledge.queue.reserved` (`{job}`) and `fledge.queue.oldest_age` (`s`, absent while nothing is pending) for every queue listed in `apm.metrics.queue_backlog.queues` (connection name to queue names), with `messaging.system`, `messaging.destination.name` and `laravel.queue.connection`. Toggle with `apm.metrics.groups.queue_backlog`; nothing is queried while the list is empty or outside long-running processes. At most one process reports: the holder of a cache lock in `apm.metrics.queue_backlog.lock_store` (default cache store), keyed by service name and environment, renewed by its owner every interval with a TTL of the interval plus half an interval of grace (2 to 30 seconds, so even a 1 second interval keeps a lease that outlasts the next renewal); its local lease ends half the grace (at least one second) before the TTL, so it never outlives a store that expires locks on whole seconds. A clean stop hands over within one interval; after a holder dies the series has a gap of up to about one TTL plus one interval. A lock store without lock support (or the `null` store) reports nothing and logs a warning. Samples never run inside the metrics export: a stock `queue:work` samples on `Looping`, between jobs; event-loop processes sample on a timer, each sample in its own fiber; Octane samples from the terminating callback once per interval, the first sample included. A process that drives its own collects through `Metrics::collectDue()` never samples the queues. A failing connection logs a throttled warning and its remaining queues are skipped for that sample, so a down database or Redis costs one connect timeout per interval.
- Runtime, loop and pool instruments in long-running processes, each group behind its own toggle. `apm.metrics.groups.loop` (only in processes driven by the event loop; a stock `queue:work`, an Octane worker receiving requests outside any fiber and a process calling `Metrics::collectDue()` record none): `fledge.loop.lag` (histogram, `s`, boundaries 0.0005 to 2.5, the drift of an unreferenced one second repeat timer) and `fledge.loop.watchers` (gauge, `{watcher}`, attribute `fledge.loop.watcher.type`: defer, delay, repeat, readable, writable, signal; every registered, non-cancelled callback, disabled and unreferenced ones and fledge-apm's own timers included). `apm.metrics.groups.runtime`: `fledge.php.memory.usage` and `fledge.php.memory.peak` (gauges, `By`), `fledge.php.gc.runs`, `fledge.php.gc.collected` and `fledge.php.gc.duration` (counters from `gc_status()`, duration in `s`). `apm.metrics.groups.database_pool`, only with a fledge-fiber release that has the pool registry: `db.client.connection.count` (attribute `db.client.connection.state` idle or used), `db.client.connection.max` and `db.client.connection.pending_requests`, per `db.client.connection.pool.name`, summed per connection name.
- `Metrics::whenLongRunning(Closure)` runs a callback once the process is detected as long-running.
- `Metrics::collectDue(): bool` for a long-running process without a continuously running event loop that drives the interval itself (torque's master calls it from its maintenance tick). It never throws. It returns false and does nothing unless metrics record, the process is long-running, no collect is in flight and `metrics.interval` has elapsed since the last collect; otherwise it collects at the root context, waits for the export (driving the event loop when called from outside one, bounded by the OTLP request timeouts) and returns whether the export succeeded. The first call opts the process out of the loop timer: it cancels a running or queued timer and none starts afterwards, so only `collectDue()`, `Looping` and the final flush export there. Such a process reports neither the queue backlog nor loop metrics, whichever of `markLongRunning()` and `collectDue()` comes first.
- `ExportDeadline` accepts an optional clock and exposes `now()`, which `FiberBatchSpanProcessor` uses for its chunk timing; without a clock the behaviour is unchanged.

### Changed

- Loop timers (the metrics collect timer, and the lag and backlog timers) arm only inside a fiber of the running event loop: at once, on the next loop turn through an unreferenced `Loop::defer()` for a process marked with `markLongRunning()` from `{main}`, or at the next detection inside a fiber for a process detected unmarked from `{main}`. The driver reporting a running loop is no longer taken as a sign: in `{main}` it stays true after any await. A stock `queue:work` (`Looping` fired outside any fiber) gets no loop timer at any moment, not even during the loop turn it takes for its signal watchers between `WorkerStarting` and the first `Looping`; a timer armed before a process turned out to be event-driven is cancelled at once.
- A stock `queue:work` now waits for the metrics export round trip on `Looping`, once per interval, before its next pop, bounded by `OTEL_EXPORTER_OTLP_TIMEOUT`, instead of leaving the export to a background fiber that would only run during a later loop turn.
- The collect timer collects only when due, with a tenth of an interval of slack, so a tick right after a `Looping` collect does not export the gauges twice.
- A failing `Metrics::whenLongRunning()` callback is logged through the throttled writer instead of silently swallowed.

- The trace sample rate and the queue backlog lock grace are bounded with the native PHP 8.6 `clamp()` instead of nested `max()` and `min()`; behaviour is unchanged (the bounds are literals in order).

### Fixed

- A process marked with `Metrics::markLongRunning()` before its event loop starts now exports on the interval and gets its lag and backlog timers: each start is queued once with an unreferenced `Loop::defer()` and arms on the first loop turn. A process that never runs a loop gets no timer and never waits for one, as before; processes detected through queue worker or Octane events queue nothing.

## [0.5.0] - 2026-11-24

### Added

- An OTLP metrics signal next to the traces, off by default (`APM_METRICS_ENABLED`). Metrics use their own transport instance, which shares the process-wide keep-alive connection pool with traces, and go to the same intake (`/v1/metrics`, or `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT`), behind the same circuit breaker, with delta temporality and no retries. Config keys under `apm.metrics`: `enabled`, `interval` (`APM_METRICS_INTERVAL`, 60 s), `temporality` (`delta`, `cumulative` or `lowmemory`), `cardinality_limit` (200), `fpm` (`APM_METRICS_FPM`), `groups.{http_client,queue,queue_backlog,runtime,database_pool,loop}` and `queue_backlog.{queues,lock_store}`; `apm.otlp.metrics_endpoint` holds the endpoint override.
- Process model: a long-running process (queue workers, Octane workers under any SAPI, daemons, anything marked with `Metrics::markLongRunning()`) exports every interval and once more when it stops; short artisan commands export nothing. `APM_METRICS_FPM` covers per-request (non-CLI) SAPIs such as PHP-FPM: only with it on are metrics recorded there, and each request's metrics are exported after the response. Octane on FrankenPHP is not a CLI process, so it needs `APM_METRICS_FPM` too, and then exports every interval as a long-running worker. An interval without a single data point sends nothing.
- The package binds `OpenTelemetry\API\Metrics\MeterProviderInterface` in the container and exposes `Fledge\Apm\Metrics\Metrics` (`meter()`, `enabled()`, `markLongRunning()`, `cardinality()`) so other packages can record through the same pipeline. Both are no-ops while metrics are off.
- Cardinality limit: every instrument keeps at most `apm.metrics.cardinality_limit` distinct attribute sets per export interval; further sets are recorded as `otel.metric.overflow=true`, and the first overflow of an instrument is logged once.
- Instruments: `http.client.request.duration` (histogram, `s`), `messaging.process.duration` (histogram, `s`), `messaging.client.consumed.messages` (counter), `fledge.queue.job.wait_time` (histogram, `s`, first attempt only, never for the sync queue), `fledge.queue.job.retries` and `fledge.queue.job.failures` (counters). Attributes are limited to `http.request.method`, `server.address`, `server.port`, `http.response.status_code`, `error.type`, `messaging.system`, `messaging.destination.name`, `laravel.queue.connection` and `laravel.job.name`; never a URL, path or job id. Toggle with `apm.metrics.groups.http_client` and `apm.metrics.groups.queue`; they are recorded only while `apm.instrumentation.http_client` and `apm.instrumentation.queue` are on. All histograms use explicit boundaries in seconds.

### Changed

- With metrics enabled, traces and metrics both carry `service.instance.id` and `fledge.process.role`. On traces the role is the SAPI default (`cli`, `fpm` or `web`) and the instance id is the pid of the process that built the tracer; detected and marked roles (for example `queue`, `octane`, or a role a package sets through `Metrics::markLongRunning()`) and the pids of forked children appear on metrics only, so APM Server's `service.node.name` is `host:pid` there. With metrics off, traces carry neither and export as before.
- `open-telemetry/sdk` is required at `^1.17` and `open-telemetry/exporter-otlp` at `^1.6`.

## [0.4.0] - 2026-11-24

### Added

- HTTP client spans gain `network.peer.address`, `network.peer.port`, `network.protocol.version`, `http.response.body.size`, `fledge.http.connect_ms`, `fledge.http.tls_ms`, `fledge.http.ttfb_ms` and `fledge.http.connection_reused`. Toggle with `apm.options.http_client.timings` (default `true`). Connection reuse and ALPN need a fledge-fiber release that reports them; without ALPN the protocol version comes from the response. `fledge.http.connect_ms` and `fledge.http.tls_ms` are omitted on a fledge-fiber release that does not report `connection_reused` (a reused connection there repeats its original connect and TLS time), and on a failed connection.
- Queue dispatches inside a trace get a PRODUCER span `send {queue}` that parents the consumer span; toggle it with `apm.options.queue.producer_spans` (default `true`). Consumer spans get `fledge.queue.wait_ms` on the first attempt, whatever that option says. A `later()` delay is included in the wait time, and a `queue:retry` resets attempts, so the retried job's wait time counts from the original dispatch. Producer spans that never see `JobQueued` (database `bulk()`, bus batches on the database driver, or a push that threw) are ended at a later sweep (Looping or the terminating callback, at most 60 s for spans of a fiber still running) without error status and carry `fledge.queue.unconfirmed`; their duration is not the push time.

### Changed

- `torque:start`, `torque:monitor`, `torque:tail` and `resonate:start` are ignored by default for console transactions.

## [0.3.0] - 2026-11-23

### Changed

- PHP 8.6 is required (`"php": "^8.6"`); CI runs on 8.6 with assertions enabled.
- The event loop comes from `webpatser/fledge-loop` ^0.1, which replaces `revolt/event-loop`. Breaking for code that uses `Revolt\EventLoop`: use `Fledge\Loop` instead. `webpatser/fledge-fiber` and `webpatser/fledge-framework` now require `^13.43.0.3`.
- Dev dependencies move to Pest 5.

## [0.2.0] - 2026-11-18

### Security

- Query bindings no longer leak through exceptions that wrap a `QueryException` (a `ViewException`, say), at any depth of the previous chain: the exception event message, the stack trace and the span status description carry the SQL with its placeholders. `apm.options.database.record_bindings` still opts in.
- `Tracer::span()` and `ActiveSpan::recordException()` go through the same redaction as the built-in instrumentations.
- `url.full` on outgoing HTTP client spans hides secrets carried in the path: Telegram bot tokens, Slack and Discord webhook secrets, and random-looking segments of 32 or more characters, including JWTs, percent-encoded base64 and segments with matrix parameters.

### Added

- `apm.options.http_client.record_full_path` (default `false`). Set it to `true` to export the path verbatim again; userinfo and query redaction still apply.

### Changed

- Under Octane, spans are exported once `max_export_batch_size` spans are buffered or `worker_flush_interval` has passed (checked when a request ends), and when the worker stops; before, a worker exported after every request. PHP-FPM, CLI and queue workers flush as before.
- Where the Fledge event loop runs (the Fledge server, torque workers), a background timer also exports an idle worker's spans every `worker_flush_interval`. Under Swoole, RoadRunner and FrankenPHP no loop runs between requests, so an idle worker keeps its spans until its next request or until it stops.
- A worker killed without a clean stop (`kill -9`, a reload past its kill timeout) loses everything buffered since the last export: up to `max_export_batch_size` minus one spans plus the last request's spans, capped by `max_queue_size`.
- A request that produced no spans (not sampled, or APM disabled) no longer starts a background flush.
- `ActiveSpan::recordException()` no longer adds a second `exception` event for an exception that was already recorded elsewhere; it only sets the error status.

### Fixed

- Running out of the flush budget partway through a flush, after the intake accepted an earlier chunk of that flush, no longer opens the shared export breaker; the rest of the batch is dropped and counted. The breaker still opens for a first chunk that exhausts the budget, a failure that returns before the deadline (a 5xx or a connect error, say), a request that runs past the flush deadline, and 3 budget cut-offs in a row on one worker; a flush that exports everything resets that count.

## [0.1.2] - 2026-10-24

### Security

- `url.path` now redacts credential-looking route parameters (`/reset-password/{token}`, `/email/verify/{id}/{hash}`), so tokens no longer reach exported spans.
- Query redaction also covers `session`, `nonce`, `code`, `otp`, `state`, `access`, `refresh` and `sig` parameter names.
- Behaviour change: `apm.http.trust_traceparent` now defaults to `false`, so an incoming `traceparent` header no longer picks the trace id of a request. Set it to `true` when callers are services you own.
- Livewire dev and suggested constraint raised to `^4.4.8` (GHSA-mhf7-xp6r-6qrc).
