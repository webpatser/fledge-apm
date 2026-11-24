# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
