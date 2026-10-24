# Changelog

## [Unreleased]

### Security

- `url.path` now redacts credential-looking route parameters (`/reset-password/{token}`, `/email/verify/{id}/{hash}`), so tokens no longer reach exported spans.
- Query redaction also covers `session`, `nonce`, `code`, `otp`, `state`, `access`, `refresh` and `sig` parameter names.
- Breaking default: `apm.http.trust_traceparent` is now `false`, so an incoming `traceparent` header no longer picks the trace id of a request. Set it to `true` when callers are services you own.
- Livewire dev and suggested constraint raised to `^4.4.8` (GHSA-mhf7-xp6r-6qrc).
