# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
