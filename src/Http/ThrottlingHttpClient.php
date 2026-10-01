<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Decorates an HttpClientInterface with a token-bucket rate limit.
 *
 * In-process only: the bucket lives in this instance's memory, so it limits
 * one PHP process's request rate, not a shared ceiling across multiple
 * PHP-FPM workers or servers. Symfony's ThrottlingHttpClient can share state
 * via its RateLimiter component's storage backend (Redis, etc.); this one
 * deliberately doesn't pull in that dependency — if a cross-process shared
 * limit is what's actually needed, this class isn't the right building
 * block, a storage-backed limiter in front of it is.
 *
 * request() blocks (busy-waits in short sleeps) until a token is available
 * rather than rejecting the call — there's no "429, try later" return path
 * here, since this client is for self-imposed outbound pacing (being a good
 * citizen against a third-party API's own rate limit), not for shedding load.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class ThrottlingHttpClient implements HttpClientInterface
{
    /** @var float Current token count, replenished lazily on each request()/wait check. */
    private float $tokens;

    /** @var float microtime(true) of the last refill computation. */
    private float $lastRefill;

    /**
     * @param HttpClientInterface $client         Underlying transport.
     * @param float               $maxTokens      Bucket capacity — the largest burst allowed.
     * @param float               $refillPerSecond Tokens added per second (the steady-state rate).
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly float $maxTokens,
        private readonly float $refillPerSecond,
    ) {
        $this->tokens = $maxTokens;
        $this->lastRefill = \microtime(true);
    }

    /**
     * @param  string               $method
     * @param  string               $url
     * @param  array<string, mixed> $options
     * @return HttpResponseInterface
     */
    public function request(string $method, string $url, array $options = []): HttpResponseInterface
    {
        $this->waitForToken();
        return $this->client->request($method, $url, $options);
    }

    /**
     * @return void
     */
    private function waitForToken(): void
    {
        $this->refill();

        while ($this->tokens < 1.0) {
            \usleep(10_000);
            $this->refill();
        }

        $this->tokens -= 1.0;
    }

    /**
     * @return void
     */
    private function refill(): void
    {
        $now = \microtime(true);
        $elapsed = $now - $this->lastRefill;

        $this->tokens = \min($this->maxTokens, $this->tokens + $elapsed * $this->refillPerSecond);
        $this->lastRefill = $now;
    }
}
