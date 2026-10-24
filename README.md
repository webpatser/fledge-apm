# Fledge APM

Fiber-aware OpenTelemetry tracing for [Fledge](https://github.com/webpatser/fledge) apps, exported as OTLP to Elastic APM or any OTel backend.

Fledge runs work in parallel fibers: Livewire lazy cards, `FiberDB::concurrent`, anything you start with `async()`. Stock OTel context storage cannot follow that, so parallel work either bleeds into the wrong span or starts a trace of its own. Fledge APM keeps one active span per fiber, and a fiber started with `async()` inherits the span of whoever started it. A request with five parallel cards shows up as one trace with five sibling spans, and the queries nest under the card that ran them.

It is independent of Laravel Pulse. Pulse stays your at-a-glance dashboard; this gives you the per-request span trees.

## Requirements

- PHP 8.5
- `webpatser/fledge-framework` ^13.34
- `webpatser/fledge-fiber` ^13.36.0.2 (required: provides `Fledge\Async\FiberContext`, which lets parallel fibers join the current trace). Without the hook the package logs a warning (at most once an hour).

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

A tracer must never take your app down with it. Spans are buffered in process and exported after the response was sent (terminating callback, off the response path). Queue workers flush on an interval and once more when they stop.

- **Flush budget:** `APM_FLUSH_BUDGET_MS` (default 1500) bounds one whole flush, including timeouts and retries. A slow or dead intake holds a PHP-FPM or queue worker for at most that long, and a flush stops at its first failed chunk.
- **No retries on hot paths:** retries only happen on the final flush (queue `WorkerStopping`, shutdown). After an HTTP response or between jobs it is one attempt per chunk.
- **Circuit breaker:** after a failed export, every worker skips exporting for `APM_BREAKER_SECONDS` (default 30) and drops its spans. The state lives in APCu when enabled, else your default cache store, else per process. "Export failure" log lines are throttled to one per window.
- **Bounded memory:** the queue holds at most `APM_MAX_QUEUE_SIZE` spans (default 2048) and exports in chunks of `APM_MAX_EXPORT_BATCH_SIZE` (default 128).

The safest setup is a collector on localhost: the app posts to `localhost:4318` in a millisecond, and the collector (or EDOT agent) handles auth, retries and the trip to Elastic.

## What gets traced

Each source can be switched off under `apm.instrumentation`.

| Source | Recorded as |
|---|---|
| HTTP kernel | Root transaction: route name, method, status code, user id. Back-dated to request start. |
| Queue jobs | Consumer transaction per job. The `traceparent` travels in the job payload, so a job continues the trace that dispatched it. |
| Artisan commands | Command transaction. Long-running or noisy commands (`queue:work`, `horizon:*`, `pulse:*`, `schedule:work`, `octane:*`, `serve`, `tinker` and friends) are skipped; see `apm.console.ignore`. |
| Database queries | DB span, back-dated by the query time. Statements are truncated at 2048 characters and bindings are never inlined. |
| Cache | Cache spans for get, put and forget. Keys are not recorded by default. |
| HTTP client | Client span per outgoing request of Laravel's `Http` client, with a `traceparent` header sent along. URLs are redacted. |
| Livewire | A `livewire update` span per update request, with mount, update, hydrate, properties, call and render spans per component below it. For `FiberHandleRequests` batches each lazy card is a sibling span. |
| Exceptions | Reported exceptions are recorded on the active span and mark the transaction as failed, which also makes the trace sampler keep it. |

Spans are only created inside a trace, so a query run by something that has no transaction (a boot-time call, say) is skipped instead of creating orphan traces.

## Privacy defaults

| Option | Default | Notes |
|---|---|---|
| `apm.http.record_client_ip` | `false` | `client.address`. |
| `apm.http.record_user_agent` | `true` | `user_agent.original`. |
| `apm.http.record_user_id` | `true` | `enduser.id`. |
| `apm.options.cache.record_keys` | `false` | Cache keys can hold PII. |
| `apm.options.database.record_bindings` | `false` | Bindings are stripped from recorded exception messages. |
| `apm.options.http_client.error_on_4xx` | `false` | Only 5xx and transport errors mark a client span as failed. |

Query bindings are never sent, request bodies and headers are never recorded, and outgoing URLs are redacted.

## Sampling

Head sampling keeps `APM_SAMPLE_RATE` of new traces. Below 1.0, traces the ratio dropped are still recorded in memory, and are exported anyway when a span failed (`keep.errors`) or the transaction was slow (`keep.slow_ms`). For plain head sampling, set `keep.errors` to `false` and `keep.slow_ms` to `0`; dropped traces then cost almost nothing.

An incoming `traceparent` is ignored by default, so a public client cannot choose your trace ids. Set `apm.http.trust_traceparent` to `true` to continue the caller's trace id when requests come from services you own (or a trusted proxy). Even then its sampled flag is not honored by default (`apm.http.honor_sampled_flag`), so an outside client cannot force or suppress sampling on your app. Turn it on if the caller is another service you own and you want one sampling decision across both.

## Known limits

- A long-running Fledge server process that never terminates the app per request does not flush yet. It needs a Revolt repeat timer, which is not built.
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

## License

MIT. See [LICENSE](LICENSE).
