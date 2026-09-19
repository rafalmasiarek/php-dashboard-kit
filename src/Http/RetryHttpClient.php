<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

use Psr\Log\LoggerInterface;

/**
 * Decorates an HttpClientInterface with retry-on-transient-failure behavior,
 * so individual callers don't each reimplement their own retry loop.
 *
 * Delegates the actual retry/idempotency policy to a RetryStrategyInterface —
 * this class only owns the attempt loop, the sleep between attempts, and
 * (when a logger is given) reporting each retry.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class RetryHttpClient implements HttpClientInterface
{
    /**
     * @param HttpClientInterface     $client   Underlying transport.
     * @param RetryStrategyInterface  $strategy Retry policy.
     * @param LoggerInterface|null    $logger   Receives an 'http.request.retry' debug entry
     *                                          for every retried attempt. Optional.
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly RetryStrategyInterface $strategy,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param  string               $method
     * @param  string               $url
     * @param  array<string, mixed> $options
     * @return HttpResponse The first response the strategy stops retrying on —
     *                       may itself be an error/non-2xx response; this method
     *                       never throws for a failure the strategy declines to retry.
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $attempt = 1;

        while (true) {
            $response = $this->client->request($method, $url, $options);

            if (!$this->strategy->shouldRetry($method, $response, $attempt)) {
                return $response;
            }

            $delayMs = $this->strategy->getDelayMilliseconds($response, $attempt);

            $this->logger?->debug('http.request.retry', [
                'method'   => $method,
                'url'      => $url,
                'attempt'  => $attempt,
                'status'   => $response->statusCode,
                'error'    => $response->error,
                'delay_ms' => $delayMs,
            ]);

            if ($delayMs > 0) {
                \usleep($delayMs * 1000);
            }

            $attempt++;
        }
    }
}
