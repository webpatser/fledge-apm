<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled, no listeners are registered and the Tracer is a no-op.
    |
    */

    'enabled' => (bool) env('APM_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Parent-based head sampling: the share of new traces to keep (0.0 - 1.0).
    | An incoming traceparent decides for its trace.
    |
    | Below 1.0, traces the ratio dropped are still recorded and kept when a
    | span failed (keep.errors) or the transaction took at least keep.slow_ms.
    | Set keep.errors to false and keep.slow_ms to 0 for plain head sampling,
    | where dropped traces are not recorded at all.
    |
    */

    'sample_rate' => (float) env('APM_SAMPLE_RATE', 1.0),

    'keep' => [
        'errors' => (bool) env('APM_KEEP_ERRORS', true),
        'slow_ms' => (int) env('APM_SLOW_THRESHOLD_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service
    |--------------------------------------------------------------------------
    */

    'service' => [
        'name' => env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel')),
        'version' => env('OTEL_SERVICE_VERSION'),
        'environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exporter
    |--------------------------------------------------------------------------
    |
    | "otlp" exports over OTLP/HTTP (Elastic APM Server, EDOT collector, any
    | OpenTelemetry collector). "memory" keeps spans in process (tests).
    |
    | Protocol: "http/protobuf" (default, the OTLP spec default every intake
    | accepts) or "http/json". Neither needs ext-protobuf; installing it
    | only makes encoding faster. gRPC is not supported.
    |
    | Headers use the OTEL format, e.g. for Elastic APM:
    | OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer%20<secret-token>"
    | or "Authorization=ApiKey%20<base64 id:key>".
    |
    */

    'exporter' => env('APM_EXPORTER', 'otlp'),

    'otlp' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),
        'traces_endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
        'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),
        'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
        // "gzip" (default) or "none".
        'compression' => env('OTEL_EXPORTER_OTLP_COMPRESSION', 'gzip'),
        // Per request, in milliseconds; always capped by batch.flush_budget_ms.
        'timeout' => (int) env('OTEL_EXPORTER_OTLP_TIMEOUT', 2000),
        'connect_timeout' => (int) env('APM_EXPORT_CONNECT_TIMEOUT', 1000),
        // Retries happen only on the final flush (queue WorkerStopping,
        // shutdown), never after an HTTP response or between jobs.
        'max_retries' => (int) env('APM_EXPORT_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    |
    | Spans are buffered in process and exported after the response was sent
    | (HTTP) or every worker_flush_interval seconds (queue workers).
    |
    | flush_budget_ms bounds one whole flush (all chunks, timeouts and
    | retries), so a slow or down intake holds a PHP-FPM or queue worker for
    | at most that long. A flush also stops at its first failed chunk.
    |
    */

    'batch' => [
        'max_queue_size' => (int) env('APM_MAX_QUEUE_SIZE', 2048),
        'max_export_batch_size' => (int) env('APM_MAX_EXPORT_BATCH_SIZE', 128),
        'worker_flush_interval' => (int) env('APM_WORKER_FLUSH_INTERVAL', 5),
        'flush_budget_ms' => (int) env('APM_FLUSH_BUDGET_MS', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After a failed export, every worker skips exporting (and drops its
    | spans) for `seconds`. SDK "Export failure" log lines are throttled to
    | one per window as well.
    |
    | Store: null picks APCu when it is enabled, else the default cache
    | store. "apcu", "local" (this process only) or any cache store name.
    | An unavailable store never throws; the breaker then works per process.
    |
    */

    'breaker' => [
        'enabled' => (bool) env('APM_BREAKER_ENABLED', true),
        'seconds' => (int) env('APM_BREAKER_SECONDS', 30),
        'store' => env('APM_BREAKER_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Instrumentation
    |--------------------------------------------------------------------------
    |
    | Toggle individual span sources. Only read when APM is enabled.
    |
    */

    'instrumentation' => [
        'http' => true,
        'queue' => true,
        'console' => true,
        'database' => true,
        'cache' => true,
        'http_client' => true,
        'livewire' => true,
        'exceptions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Console
    |--------------------------------------------------------------------------
    |
    | Commands that get no transaction. Takes command names and "*" patterns
    | and replaces the built-in list. Null keeps the default: long-running or
    | noisy commands (queue:work, queue:listen, horizon, horizon:*, pulse:work,
    | pulse:check, schedule:work, reverb:start, octane:*, serve, tinker, list,
    | help, _complete, completion). Set to [] to trace every command.
    |
    */

    'console' => [
        'ignore' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming HTTP requests
    |--------------------------------------------------------------------------
    |
    | Tuning for the HTTP transaction. Only read when the http
    | instrumentation is on.
    |
    */

    'http' => [
        // Continue the trace id of an incoming traceparent header. Turn off
        // when the app is exposed to clients you do not control.
        'trust_traceparent' => true,
        // Let the caller's sampled flag override the local sample_rate.
        // Off by default so a client cannot force or suppress sampling.
        'honor_sampled_flag' => false,
        // Record the client IP as client.address. Off by default (PII).
        'record_client_ip' => false,
        // Record the User-Agent header as user_agent.original.
        'record_user_agent' => true,
        // Record the authenticated user id as enduser.id.
        'record_user_id' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Instrumentation options
    |--------------------------------------------------------------------------
    |
    | Per-instrumentation tuning. Only read when the instrumentation is on.
    |
    */

    'options' => [
        'database' => [
            // Statements longer than this are truncated (0 truncates to nothing).
            'max_statement_length' => 2048,
            // Also set the legacy db.system and db.statement attributes.
            'legacy_attributes' => true,
            // Keep query bindings in recorded exception messages. Off by
            // default, bindings may hold PII.
            'record_bindings' => false,
        ],
        'cache' => [
            // Record cache keys on spans. Off by default, keys may hold PII.
            'record_keys' => false,
        ],
        'http_client' => [
            // Send a traceparent header on outgoing requests.
            'propagate' => true,
            // Mark 4xx responses as span errors (5xx always are).
            'error_on_4xx' => false,
        ],
    ],

];
