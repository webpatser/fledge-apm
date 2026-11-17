# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Security

- Query bindings no longer leak through exceptions that wrap a `QueryException` (a `ViewException`, say), at any depth of the previous chain: the exception event message, the stack trace and the span status description carry the SQL with its placeholders. `apm.options.database.record_bindings` still opts in.
- `Tracer::span()` and `ActiveSpan::recordException()` go through the same redaction as the built-in instrumentations.
- `url.full` on outgoing HTTP client spans hides secrets carried in the path: Telegram bot tokens, Slack and Discord webhook secrets, and random-looking segments of 32 or more characters, including JWTs, percent-encoded base64 and segments with matrix parameters.

### Added

- `apm.options.http_client.record_full_path` (default `false`). Set it to `true` to export the path verbatim again; userinfo and query redaction still apply.

### Changed

- `ActiveSpan::recordException()` no longer adds a second `exception` event for an exception that was already recorded elsewhere; it only sets the error status.

## [0.1.2] - 2026-10-24

### Security

- `url.path` now redacts credential-looking route parameters (`/reset-password/{token}`, `/email/verify/{id}/{hash}`), so tokens no longer reach exported spans.
- Query redaction also covers `session`, `nonce`, `code`, `otp`, `state`, `access`, `refresh` and `sig` parameter names.
- Behaviour change: `apm.http.trust_traceparent` now defaults to `false`, so an incoming `traceparent` header no longer picks the trace id of a request. Set it to `true` when callers are services you own.
- Livewire dev and suggested constraint raised to `^4.4.8` (GHSA-mhf7-xp6r-6qrc).
