<?php

declare(strict_types=1);

use Fledge\Apm\Context\FiberContextStorage;
use Fledge\Apm\Export\ExportCircuitBreaker;
use Fledge\Apm\Export\ExportDeadline;
use Fledge\Apm\Export\OtlpHttpTransport;
use Fledge\Apm\Export\ThrottledLogWriter;
use Fledge\Apm\Tracer;
use Fledge\Apm\TracerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

use function Fledge\Async\delay;

beforeEach(fn () => Logging::disable());

afterEach(fn () => Logging::reset());

/**
 * A Tracer exporting OTLP through a Guzzle client on a MockHandler, with the
 * production timeouts (2 s request, 1 s connect, 3 retries).
 *
 * @param  list<mixed>  $queue  MockHandler responses or callables
 */
function budgetTracer(array $queue, int $budgetMs, int $chunk = 128, ?ExportCircuitBreaker $breaker = null): Tracer
{
    $factory = new HttpFactory;

    $transport = new OtlpHttpTransport(
        client: new Client(['handler' => HandlerStack::create(new MockHandler($queue))]),
        requestFactory: $factory,
        streamFactory: $factory,
        endpoint: 'http://apm.test:8200/v1/traces',
        contentType: ContentTypes::PROTOBUF,
        maxRetries: 3,
        retryDelaySeconds: 0.05,
        timeout: 2.0,
        connectTimeout: 1.0,
    );

    return TracerFactory::make([
        'enabled' => true,
        'keep' => ['slow_ms' => 0],
        'batch' => ['flush_budget_ms' => $budgetMs, 'max_export_batch_size' => $chunk],
    ], new FiberContextStorage, new SpanExporter($transport), $breaker);
}

/**
 * @return array{bool, float} flush result and elapsed seconds
 */
function timedFlush(Tracer $tracer, bool $retry = false): array
{
    $start = hrtime(true);
    $result = $tracer->flush($retry);

    return [$result, (hrtime(true) - $start) / 1_000_000_000];
}

function failingExporter(): InMemoryExporter
{
    return new class extends InMemoryExporter
    {
        public int $exports = 0;

        public bool $fail = true;

        protected function doExport(iterable $spans): bool
        {
            $this->exports++;

            return $this->fail ? false : parent::doExport($spans);
        }
    };
}

/**
 * An SDK exporter for a healthy intake with `$latency` seconds per chunk that
 * reads the flush deadline instead of racing it. A chunk that starts with at
 * least `$latency` left is accepted after a busy-wait of exactly `$latency`.
 * A chunk that starts with less is the budget-capped request: it waits until
 * the deadline reports expired and only then fails, so it always ends right
 * at the deadline, however loaded the machine is.
 */
function latencyExporter(float $latency): SpanExporterInterface
{
    return new class($latency) implements SpanExporterInterface
    {
        /** @var list<float> budget left (seconds) when each chunk started */
        public array $calls = [];

        public function __construct(private readonly float $latency) {}

        public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
        {
            assert($cancellation instanceof ExportDeadline);

            $remaining = $cancellation->remainingSeconds();
            $this->calls[] = $remaining;

            if ($remaining < $this->latency) {
                while (! $cancellation->expired()) {
                    // spin until the deadline
                }

                return new CompletedFuture(false);
            }

            $until = hrtime(true) + (int) ($this->latency * 1_000_000_000);

            while (hrtime(true) < $until) {
                // spin: the intake's latency
            }

            return new CompletedFuture(true);
        }

        public function shutdown(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }

        public function forceFlush(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }
    };
}

it('keeps a flush against an intake that never accepts the connection within the budget', function () {
    $calls = [];

    // Like a real client: waits out its connect timeout, then gives up.
    $deadIntake = function (RequestInterface $request, array $options) use (&$calls): ConnectException {
        $calls[] = $options;
        delay(min((float) $options['connect_timeout'], 5.0));

        return new ConnectException('Connection timed out', $request);
    };

    $tracer = budgetTracer(array_fill(0, 20, $deadIntake), budgetMs: 300, chunk: 1);

    foreach (range(1, 4) as $i) {
        $tracer->span("span {$i}", fn () => null);
    }

    [$result, $elapsed] = timedFlush($tracer);

    // Unbounded this was 4 chunks x 4 attempts x 1 s connect timeout.
    expect($result)->toBeFalse()
        ->and($elapsed)->toBeLessThan(0.45)
        ->and($calls)->toHaveCount(1) // no retry, and no further chunk after the failed one
        ->and($calls[0]['connect_timeout'])->toBeLessThanOrEqual(0.3)
        ->and($calls[0]['timeout'])->toBeLessThanOrEqual(0.3);
});

it('keeps a flush against a slow responder within the budget', function () {
    $calls = [];

    $slowIntake = function (RequestInterface $request, array $options) use (&$calls): Response|ConnectException {
        $calls[] = $options;
        $timeout = (float) $options['timeout'];

        if ($timeout < 3.0) {
            delay($timeout);

            return new ConnectException("Operation timed out after {$timeout} s", $request);
        }

        delay(3.0);

        return new Response(200);
    };

    $tracer = budgetTracer(array_fill(0, 20, $slowIntake), budgetMs: 300);

    $tracer->span('slow intake', fn () => null);

    [$result, $elapsed] = timedFlush($tracer);

    expect($result)->toBeFalse()
        ->and($elapsed)->toBeLessThan(0.45)
        ->and($calls)->toHaveCount(1);
});

it('retries on the final flush, but only within the budget', function () {
    $calls = 0;

    $refused = function (RequestInterface $request) use (&$calls): ConnectException {
        $calls++;

        return new ConnectException('Connection refused', $request);
    };

    $tracer = budgetTracer(array_fill(0, 20, $refused), budgetMs: 300);

    $tracer->span('final', fn () => null);

    [$result, $elapsed] = timedFlush($tracer, retry: true);

    // Backoffs 0.05 + 0.1 fit, the 0.2 after that does not.
    expect($result)->toBeFalse()
        ->and($calls)->toBeGreaterThan(1)->toBeLessThan(4)
        ->and($elapsed)->toBeLessThan(0.45);
});

it('stops a flush at the first failed chunk', function () {
    $exporter = failingExporter();

    $tracer = TracerFactory::make([
        'enabled' => true,
        'keep' => ['slow_ms' => 0],
        'batch' => ['max_export_batch_size' => 1],
    ], new FiberContextStorage, $exporter);

    foreach (range(1, 3) as $i) {
        $tracer->span("span {$i}", fn () => null);
    }

    expect($tracer->flush())->toBeFalse()
        ->and($exporter->exports)->toBe(1);
});

it('opens the breaker for every worker after a failure, skips exports while open and closes after the window', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(0.2, static fn (): Repository => $store, 'fledge-apm:breaker:test');
    $otherWorker = new ExportCircuitBreaker(0.2, static fn (): Repository => $store, 'fledge-apm:breaker:test');
    $exporter = failingExporter();

    $tracer = TracerFactory::make(['enabled' => true, 'keep' => ['slow_ms' => 0]], new FiberContextStorage, $exporter, $breaker);

    expect($otherWorker->isOpen())->toBeFalse();

    $tracer->span('fails', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($exporter->exports)->toBe(1)
        ->and($breaker->isOpen())->toBeTrue()
        ->and($otherWorker->isOpen())->toBeTrue();

    $exporter->fail = false;
    $tracer->span('skipped while open', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($exporter->exports)->toBe(1);

    usleep(250_000);

    $tracer->span('after the window', fn () => null);

    expect($breaker->isOpen())->toBeFalse()
        ->and($otherWorker->isOpen())->toBeFalse()
        ->and($tracer->flush())->toBeTrue()
        ->and($exporter->exports)->toBe(2)
        ->and(array_map(fn (SpanDataInterface $span) => $span->getName(), $exporter->getSpans()))->toBe(['after the window']);
});

it('does not open the breaker when the flush budget runs out after the intake accepted earlier chunks', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(30, static fn (): Repository => $store, 'fledge-apm:breaker:budget');

    // A healthy intake with moderate latency: each chunk takes 150 ms.
    $exporter = latencyExporter(0.15);

    $tracer = TracerFactory::make([
        'enabled' => true,
        'keep' => ['slow_ms' => 0],
        'batch' => ['flush_budget_ms' => 400, 'max_export_batch_size' => 1],
    ], new FiberContextStorage, $exporter, $breaker);

    foreach (range(1, 6) as $i) {
        $tracer->span("span {$i}", fn () => null);
    }

    // Some chunks fit, the next times out on the budget (it started with
    // less budget than the intake's latency), the rest never start.
    expect($tracer->flush())->toBeFalse()
        ->and(count($exporter->calls))->toBeGreaterThanOrEqual(2)->toBeLessThan(6)
        ->and(end($exporter->calls))->toBeLessThan(0.15)
        ->and($breaker->isOpen())->toBeFalse();

    $before = count($exporter->calls);
    $tracer->span('next request', fn () => null);

    expect($tracer->flush())->toBeTrue()
        ->and($exporter->calls)->toHaveCount($before + 1);
});

it('still opens the breaker when the first chunk of a flush outlasts the budget', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(30, static fn (): Repository => $store, 'fledge-apm:breaker:stalled');

    // Accepts the connection, never answers.
    $stalledIntake = function (RequestInterface $request, array $options): ConnectException {
        delay((float) $options['timeout']);

        return new ConnectException('Operation timed out', $request);
    };

    $tracer = budgetTracer(array_fill(0, 20, $stalledIntake), budgetMs: 200, breaker: $breaker);

    $tracer->span('stalled', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($breaker->isOpen())->toBeTrue();
});

it('still opens the breaker when the intake rejects a later chunk within the budget', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(30, static fn (): Repository => $store, 'fledge-apm:breaker:rejected');

    $tracer = budgetTracer([new Response(200), new Response(503, [], 'busy')], budgetMs: 1000, chunk: 1, breaker: $breaker);

    $tracer->span('accepted', fn () => null);
    $tracer->span('rejected', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($breaker->isOpen())->toBeTrue();
});

it('opens the breaker on a fast intake failure in the last moments of the budget', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(30, static fn (): Repository => $store, 'fledge-apm:breaker:late-503');

    // An SDK exporter that sees the flush deadline: chunk 1 is accepted 6 ms
    // before it (busy-wait, exact to the microsecond), chunk 2 fails at once
    // like a fast 503. Guzzle's own per-chunk overhead is too coarse for this.
    $exporter = new class implements SpanExporterInterface
    {
        /** @var list<float> budget left (seconds) when chunk 2 started */
        public array $rejectedWith = [];

        private int $calls = 0;

        public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
        {
            assert($cancellation instanceof ExportDeadline);

            if (++$this->calls === 1) {
                while (hrtime(true) < $cancellation->deadlineNanos - 6_000_000) {
                    // spin
                }

                return new CompletedFuture(true);
            }

            $this->rejectedWith[] = $cancellation->remainingSeconds();

            return new CompletedFuture(false);
        }

        public function shutdown(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }

        public function forceFlush(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }
    };

    $tracer = TracerFactory::make([
        'enabled' => true,
        'keep' => ['slow_ms' => 0],
        'batch' => ['flush_budget_ms' => 300, 'max_export_batch_size' => 1],
    ], new FiberContextStorage, $exporter, $breaker);

    $tracer->span('accepted', fn () => null);
    $tracer->span('rejected', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($exporter->rejectedWith)->toHaveCount(1)
        ->and($exporter->rejectedWith[0])->toBeGreaterThan(0.0)->toBeLessThan(0.01)
        ->and($breaker->isOpen())->toBeTrue();
});

it('opens the breaker when a client without a capped timeout hangs past the deadline', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(30, static fn (): Repository => $store, 'fledge-apm:breaker:psr18');
    $factory = new HttpFactory;

    // A plain PSR-18 client keeps its own timeout: the second request hangs
    // well past the flush deadline before it fails.
    $client = new class implements ClientInterface
    {
        public int $calls = 0;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            if (++$this->calls === 1) {
                return new Response(200);
            }

            delay(0.35);

            return new Response(504, [], 'gateway timeout');
        }
    };

    $transport = new OtlpHttpTransport($client, $factory, $factory, 'http://apm.test:8200/v1/traces', ContentTypes::PROTOBUF);

    $tracer = TracerFactory::make([
        'enabled' => true,
        'keep' => ['slow_ms' => 0],
        'batch' => ['flush_budget_ms' => 200, 'max_export_batch_size' => 1],
    ], new FiberContextStorage, new SpanExporter($transport), $breaker);

    $tracer->span('accepted', fn () => null);
    $tracer->span('hangs', fn () => null);

    expect($tracer->flush())->toBeFalse()
        ->and($client->calls)->toBe(2)
        ->and($breaker->isOpen())->toBeTrue();
});

it('opens the breaker after three consecutive flushes cut off by the budget, and a full export resets the count', function () {
    $store = new Repository(new ArrayStore);
    $breaker = new ExportCircuitBreaker(30, static fn (): Repository => $store, 'fledge-apm:breaker:slow-alive');

    // Slow but alive: every chunk takes 150 ms, so with a 300 ms budget the
    // second chunk of a flush is always cut off by the budget.
    $tracer = TracerFactory::make([
        'enabled' => true,
        'keep' => ['slow_ms' => 0],
        'batch' => ['flush_budget_ms' => 300, 'max_export_batch_size' => 1],
    ], new FiberContextStorage, latencyExporter(0.15), $breaker);

    $cutOffFlush = function () use ($tracer): bool {
        foreach (range(1, 3) as $i) {
            $tracer->span("span {$i}", fn () => null);
        }

        return $tracer->flush();
    };

    expect($cutOffFlush())->toBeFalse()
        ->and($cutOffFlush())->toBeFalse()
        ->and($breaker->isOpen())->toBeFalse();

    // One span fits: the flush exports everything and resets the count.
    $tracer->span('fits', fn () => null);

    expect($tracer->flush())->toBeTrue()
        ->and($cutOffFlush())->toBeFalse()
        ->and($cutOffFlush())->toBeFalse()
        ->and($breaker->isOpen())->toBeFalse()
        ->and($cutOffFlush())->toBeFalse()
        ->and($breaker->isOpen())->toBeTrue();
});

it('never throws when the breaker store is unavailable', function () {
    $broken = new ExportCircuitBreaker(30, static fn (): Repository => throw new RuntimeException('cache down'));

    expect($broken->isOpen())->toBeFalse();

    $broken->recordFailure();

    expect($broken->isOpen())->toBeTrue()
        ->and($broken->firstInWindow('log'))->toBeTrue()
        ->and($broken->firstInWindow('log'))->toBeFalse();

    $missing = ExportCircuitBreaker::fromConfig(
        ['store' => 'no-such-store', 'seconds' => 30],
        'http://apm.test:8200/v1/traces',
        static fn (?string $name): Repository => app('cache')->store($name),
    );

    expect($missing->isOpen())->toBeFalse();

    $missing->recordFailure();

    expect($missing->isOpen())->toBeTrue();
});

it('logs SDK export failures through the Laravel logger at most once per window', function () {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{mixed, string}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message];
        }
    };

    Logging::setLogWriter(new ThrottledLogWriter(fn () => $logger, new ExportCircuitBreaker(30)));

    // No breaker on the processor: both flushes really fail and the SDK logs twice.
    $tracer = budgetTracer([new Response(400, [], 'bad'), new Response(400, [], 'bad')], budgetMs: 1000);

    $tracer->span('first', fn () => null);
    $tracer->flush();
    $tracer->span('second', fn () => null);
    $tracer->flush();

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0])->toBe(['error', 'OpenTelemetry: Export failure']);
});

it('defaults to gzip, 128-span chunks, a 1500 ms flush budget and short timeouts', function () {
    $transport = TracerFactory::transport((array) config('apm'));

    expect(config('apm.otlp.compression'))->toBe('gzip')
        ->and((new ReflectionProperty($transport, 'compression'))->getValue($transport))->toBe('gzip')
        ->and((new ReflectionProperty($transport, 'timeout'))->getValue($transport))->toBe(2.0)
        ->and((new ReflectionProperty($transport, 'connectTimeout'))->getValue($transport))->toBe(1.0)
        ->and(TracerFactory::compression(null))->toBe('gzip')
        ->and(TracerFactory::compression('none'))->toBeNull()
        ->and(config('apm.batch.max_export_batch_size'))->toBe(128)
        ->and(config('apm.batch.flush_budget_ms'))->toBe(1500)
        ->and(config('apm.breaker.seconds'))->toBe(30);
});
