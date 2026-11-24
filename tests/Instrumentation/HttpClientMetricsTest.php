<?php

declare(strict_types=1);

use Fledge\Apm\Instrumentation\HttpClientInstrumentation;
use Fledge\Apm\Metrics\CardinalityGuard;
use Fledge\Apm\Metrics\Metrics;
use Fledge\Apm\Tracer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;

/**
 * An enabled Metrics runtime exporting into an in-memory exporter, with the
 * HTTP client instrumentation registered on a tracer that exports spans into
 * memory too.
 *
 * @param  array<string, mixed>  $metricsConfig
 * @return array{Metrics, InMemoryExporter, Tracer}
 */
function httpMetricsRuntime(array $metricsConfig = [], string $sapi = 'cli', array $tracerConfig = []): array
{
    config()->set('apm.enabled', true);
    config()->set('apm.metrics', array_replace_recursive(config('apm.metrics'), ['enabled' => true], $metricsConfig));

    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    $metrics = new Metrics(config('apm'), $exporter, sapi: $sapi);
    app()->instance(Metrics::class, $metrics);

    [$tracer] = memoryTracer($tracerConfig);
    (new HttpClientInstrumentation)->register(app(), $tracer);

    return [$metrics, $exporter, $tracer];
}

/**
 * Mark the process long-running, collect once and index the export by name.
 *
 * @return array<string, Metric>
 */
function httpMetricsExported(Metrics $metrics, InMemoryExporter $exporter): array
{
    $metrics->detect();
    $metrics->collect();

    $byName = [];

    foreach ($exporter->collect() as $metric) {
        $byName[$metric->name] = $metric;
    }

    $metrics->stop();

    return $byName;
}

/**
 * @return list<array<string, mixed>>
 */
function httpMetricsAttributeSets(Metric $metric): array
{
    $sets = [];

    foreach ($metric->data->dataPoints as $point) {
        $sets[] = $point->attributes->toArray();
    }

    return $sets;
}

const HTTP_METRICS_ALLOWED = ['http.request.method', 'server.address', 'server.port', 'http.response.status_code', 'error.type'];

it('records http.client.request.duration with its name, unit and semconv boundaries', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/items?page=2');
    $parent->end();

    $exported = httpMetricsExported($metrics, $exporter);

    expect($exported)->toHaveKey('http.client.request.duration');

    $metric = $exported['http.client.request.duration'];
    $point = $metric->data->dataPoints[0];

    expect($metric->unit)->toBe('s')
        ->and($point->explicitBounds)->toEqual([0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10])
        ->and($point->count)->toBe(1)
        ->and($point->sum)->toBeFloat()->toBeGreaterThan(0.0)->toBeLessThan(5.0);
});

it('records only the allowed attributes and never the URL or path', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/items/secret-path?token=abc123');
    $parent->end();

    $sets = httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']);

    expect($sets)->toHaveCount(1)
        ->and($sets[0])->toEqual([
            'http.request.method' => 'GET',
            'server.address' => 'api.example.test',
            'server.port' => 443,
            'http.response.status_code' => 200,
        ])
        ->and(array_diff(array_keys($sets[0]), HTTP_METRICS_ALLOWED))->toBe([])
        ->and(json_encode($sets))->not->toContain('secret-path')->not->toContain('abc123');
});

it('derives error.type from the status the span marks as an error', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake([
        'ok.example.test/*' => Http::response('ok', 200),
        'missing.example.test/*' => Http::response('nope', 404),
        'broken.example.test/*' => Http::response('nope', 503),
    ]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://ok.example.test/');
    Http::get('https://missing.example.test/');
    Http::get('https://broken.example.test/');
    $parent->end();

    $byHost = [];

    foreach (httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']) as $set) {
        $byHost[$set['server.address']] = $set;
    }

    expect($byHost['ok.example.test'])->not->toHaveKey('error.type')
        ->and($byHost['missing.example.test'])->not->toHaveKey('error.type')
        ->and($byHost['missing.example.test']['http.response.status_code'])->toBe(404)
        ->and($byHost['broken.example.test']['error.type'])->toBe('503')
        ->and($byHost['broken.example.test']['http.response.status_code'])->toBe(503);
});

it('marks 4xx responses with error.type when error_on_4xx is on, like the span', function () {
    config()->set('apm.options.http_client.error_on_4xx', true);

    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(['*' => Http::response('nope', 404)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();

    $sets = httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']);

    expect($sets[0]['error.type'])->toBe('404');
});

it('records a failed connection with the exception class short name and no status', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to secret.internal'));

    $parent = $tracer->startSpan('GET /');
    expect(fn () => Http::get('https://down.example.test/'))->toThrow(ConnectionException::class);
    $parent->end();

    $sets = httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']);

    expect($sets)->toHaveCount(1)
        ->and($sets[0]['error.type'])->toBe('ConnectionException')
        ->and($sets[0])->not->toHaveKey('http.response.status_code')
        ->and(json_encode($sets))->not->toContain('secret.internal');
});

it('records the metric with options.http_client.timings off', function () {
    config()->set('apm.options.http_client.timings', false);

    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();

    expect(httpMetricsExported($metrics, $exporter))->toHaveKey('http.client.request.duration');
});

it('records the metric when the span is not sampled and outside any trace', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime(tracerConfig: [
        'sample_rate' => 0.0,
        'keep' => ['errors' => false, 'slow_ms' => 0],
    ]);

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://dropped.example.test/');
    $parent->end();

    // No span is current here at all.
    Http::get('https://untraced.example.test/');

    $hosts = array_column(httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']), 'server.address');

    expect($hosts)->toEqualCanonicalizing(['dropped.example.test', 'untraced.example.test']);
});

it('maps an unknown method to _OTHER', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::send('PURGE', 'https://api.example.test/');
    $parent->end();

    $sets = httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']);

    expect($sets[0]['http.request.method'])->toBe('_OTHER');
});

it('records the overflow set once the cardinality limit is exceeded', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime(['cardinality_limit' => 2]);

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');

    foreach (['a', 'b', 'c', 'd'] as $host) {
        Http::get("https://{$host}.example.test/");
    }

    $parent->end();

    $sets = httpMetricsAttributeSets(httpMetricsExported($metrics, $exporter)['http.client.request.duration']);
    $overflow = array_values(array_filter($sets, fn (array $set): bool => isset($set[CardinalityGuard::OVERFLOW_ATTRIBUTE])));

    expect($sets)->toHaveCount(3)
        ->and($overflow)->toHaveCount(1)
        ->and($overflow[0])->toBe([CardinalityGuard::OVERFLOW_ATTRIBUTE => true]);
});

it('records nothing with metrics.groups.http_client off', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime(['groups' => ['http_client' => false]]);

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();

    expect(httpMetricsExported($metrics, $exporter))->toBe([]);
});

it('exports nothing from a short-lived CLI process', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime();

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();
    app()->terminate();

    expect($metrics->longRunning())->toBeFalse()
        ->and($metrics->collect())->toBeFalse()
        ->and($exporter->collect())->toBe([]);
});

it('exports nothing under PHP-FPM with metrics.fpm off', function () {
    [$metrics, $exporter, $tracer] = httpMetricsRuntime(['fpm' => false], 'fpm-fcgi');

    Http::fake(['*' => Http::response('ok', 200)]);

    $parent = $tracer->startSpan('GET /');
    Http::get('https://api.example.test/');
    $parent->end();
    app()->terminate();

    expect(Metrics::enabled())->toBeFalse()
        ->and($exporter->collect())->toBe([]);
});
