# Changelog

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.2] - 2026-10-24

### Security

- `url.path` now redacts credential-looking route parameters (`/reset-password/{token}`, `/email/verify/{id}/{hash}`), so tokens no longer reach exported spans.
- Query redaction also covers `session`, `nonce`, `code`, `otp`, `state`, `access`, `refresh` and `sig` parameter names.
- Behaviour change: `apm.http.trust_traceparent` now defaults to `false`, so an incoming `traceparent` header no longer picks the trace id of a request. Set it to `true` when callers are services you own.
- Livewire dev and suggested constraint raised to `^4.4.8` (GHSA-mhf7-xp6r-6qrc).
