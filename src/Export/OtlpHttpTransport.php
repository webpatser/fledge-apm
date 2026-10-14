<?php

declare(strict_types=1);

namespace Fledge\Apm\Export;

use BadMethodCallException;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\RequestOptions;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

use function Fledge\Async\delay;

/**
 * OTLP/HTTP transport over any PSR-18 client; in production a Guzzle client
 * on FledgeGuzzle::stack(), so an export suspends only its own fiber.
 *
 * Replaces the SDK's PsrTransport, whose retry backoff blocks the whole
 * process with time_nanosleep(). Retries here wait with Fledge\Async\delay().
 *
 * With an ExportDeadline as the cancellation (every Tracer flush passes
 * one), request timeouts and backoffs are capped by the flush budget, and
 * retries only happen when the deadline allows them (final flushes).
 *
 * @implements TransportInterface<string>
 */
final class OtlpHttpTransport implements TransportInterface
{
    private const array RETRYABLE_STATUSES = [429, 502, 503, 504];

    private bool $closed = false;

    /**
     * @param  array<string, string>  $headers
     * @param  'gzip'|null  $compression
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $endpoint,
        private readonly string $contentType,
        private readonly array $headers = [],
        private readonly ?string $compression = null,
        private readonly int $maxRetries = 3,
        private readonly float $retryDelaySeconds = 0.1,
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
    ) {}

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->closed) {
            return new ErrorFuture(new BadMethodCallException('The OTLP transport is shut down.'));
        }

        $request = $this->requestFactory->createRequest('POST', $this->endpoint)
            ->withHeader('Content-Type', $this->contentType);

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($this->compression === 'gzip') {
            $payload = (string) gzencode($payload);
            $request = $request->withHeader('Content-Encoding', 'gzip');
        }

        $request = $request->withBody($this->streamFactory->createStream($payload));

        $deadline = $cancellation instanceof ExportDeadline ? $cancellation : null;
        $maxRetries = $deadline === null || $deadline->retry ? $this->maxRetries : 0;

        for ($attempt = 0; ; $attempt++) {
            if ($deadline?->expired()) {
                return new ErrorFuture(new RuntimeException(sprintf(
                    'OTLP export to %s abandoned: the flush budget is exhausted.',
                    $this->endpoint,
                )));
            }

            try {
                $response = $this->dispatch($request, $deadline);
            } catch (NetworkExceptionInterface $exception) {
                if ($attempt >= $maxRetries || ! $this->wait($this->backoff($attempt), $deadline)) {
                    return new ErrorFuture($exception);
                }

                continue;
            } catch (ClientExceptionInterface $exception) {
                return new ErrorFuture($exception);
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return new CompletedFuture($this->responsePayload($response));
            }

            if (
                in_array($status, self::RETRYABLE_STATUSES, true)
                && $attempt < $maxRetries
                && $this->wait($this->retryAfter($response) ?? $this->backoff($attempt), $deadline)
            ) {
                continue;
            }

            return new ErrorFuture(new RuntimeException(sprintf(
                'OTLP export to %s failed with HTTP %d: %s',
                $this->endpoint,
                $status,
                mb_substr((string) $response->getBody(), 0, 300),
            )));
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->closed = true;

        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return ! $this->closed;
    }

    /**
     * The body for the SDK to decode as an ExportTraceServiceResponse, only
     * when it is in our content type (some intakes answer protobuf requests
     * with an empty JSON object).
     */
    private function responsePayload(ResponseInterface $response): ?string
    {
        $body = (string) $response->getBody();

        if ($body === '' || ! str_starts_with(strtolower($response->getHeaderLine('Content-Type')), $this->contentType)) {
            return null;
        }

        return $body;
    }

    /**
     * A Guzzle client gets per-request timeouts capped by the flush budget, so
     * one stalled connection can never outlast it. Any other PSR-18 client
     * keeps its own timeouts; the budget is then checked between attempts.
     */
    private function dispatch(RequestInterface $request, ?ExportDeadline $deadline): ResponseInterface
    {
        if (! $this->client instanceof GuzzleClientInterface) {
            return $this->client->sendRequest($request);
        }

        $timeout = $this->timeout;

        if ($deadline !== null) {
            $timeout = min($timeout, max(0.001, $deadline->remainingSeconds()));
        }

        return $this->client->send($request, [
            RequestOptions::SYNCHRONOUS => true,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::CONNECT_TIMEOUT => min($this->connectTimeout, $timeout),
        ]);
    }

    /**
     * Wait before a retry, unless the wait would not fit the budget.
     */
    private function wait(float $seconds, ?ExportDeadline $deadline): bool
    {
        if ($deadline !== null && $seconds >= $deadline->remainingSeconds()) {
            return false;
        }

        delay($seconds);

        return true;
    }

    private function backoff(int $attempt): float
    {
        return $this->retryDelaySeconds * (2 ** $attempt);
    }

    private function retryAfter(ResponseInterface $response): ?float
    {
        $header = $response->getHeaderLine('Retry-After');

        return is_numeric($header) ? min((float) $header, 30.0) : null;
    }
}
