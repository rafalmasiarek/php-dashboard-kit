<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Decorates an HttpClientInterface with a practical subset of RFC 9111 (HTTP
 * Caching): freshness via Cache-Control max-age or Expires, and revalidation
 * via ETag/Last-Modified once an entry goes stale. Only GET and HEAD are
 * cached; everything else passes straight through.
 *
 * Deliberately out of scope, compared to a full RFC 9111 implementation (and
 * Symfony's CachingHttpClient): no Vary support (a cached entry is reused for
 * any request to the same URL regardless of what varied the original
 * response), no stale-while-revalidate/stale-if-error, no request
 * collapsing. A response that sets Cache-Control but doesn't give a usable
 * freshness lifetime (no max-age, no Expires) is treated as not cacheable —
 * this errs conservative (never serves something stale by accident) rather
 * than guessing at a default TTL the way some implementations do.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class CachingHttpClient implements HttpClientInterface
{
    /** @var list<string> Methods eligible for caching. */
    private const CACHEABLE_METHODS = ['GET', 'HEAD'];

    /**
     * @param HttpClientInterface     $client Underlying transport.
     * @param HttpCacheStoreInterface $store  Where cached entries are persisted.
     * @param int                     $maxTtl Upper bound on any computed freshness lifetime, in seconds.
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly HttpCacheStoreInterface $store,
        private readonly int $maxTtl = 86400,
    ) {
    }

    /**
     * @param  string               $method
     * @param  string               $url
     * @param  array<string, mixed> $options
     * @return HttpResponseInterface
     */
    public function request(string $method, string $url, array $options = []): HttpResponseInterface
    {
        $method = \strtoupper($method);

        if (!\in_array($method, self::CACHEABLE_METHODS, true)) {
            return $this->client->request($method, $url, $options);
        }

        $key = $this->cacheKey($method, $url);
        $cached = $this->store->get($key);

        if ($cached !== null && (int) $cached['expires_at'] > \time()) {
            return $this->fromCachedEntry($cached);
        }

        // $cached is either null (true miss) or expired (stale, but its etag/last_modified
        // are still worth sending as conditional-request validators) — either way,
        // fetchAndMaybeCache() does the right thing with it.
        return $this->fetchAndMaybeCache($method, $url, $options, $key, $cached);
    }

    /**
     * @param  array<string, mixed> $entry
     * @return HttpResponseInterface
     */
    private function fromCachedEntry(array $entry): HttpResponseInterface
    {
        return new HttpResponse(
            statusCode: (int) $entry['status'],
            headers: (array) $entry['headers'],
            body: (string) $entry['body'],
        );
    }

    /**
     * @param  string                     $method
     * @param  string                     $url
     * @param  array<string, mixed>       $options
     * @param  string                     $key
     * @param  array<string, mixed>|null  $stale A previously-cached entry that expired, whose
     *                                            validators (ETag/Last-Modified) can be sent for
     *                                            a conditional revalidation instead of a full refetch.
     * @return HttpResponseInterface
     */
    private function fetchAndMaybeCache(string $method, string $url, array $options, string $key, ?array $stale): HttpResponseInterface
    {
        if ($stale !== null) {
            $headers = (array) ($options['headers'] ?? []);
            if (!empty($stale['etag'])) {
                $headers['If-None-Match'] = $stale['etag'];
            }
            if (!empty($stale['last_modified'])) {
                $headers['If-Modified-Since'] = $stale['last_modified'];
            }
            $options['headers'] = $headers;
        }

        $response = $this->client->request($method, $url, $options);

        if ($stale !== null && $response->getStatusCode() === 304) {
            $ttl = $this->computeTtl($response) ?? $this->maxTtl;
            $this->store->set($key, $stale, $ttl);
            return $this->fromCachedEntry($stale);
        }

        if ($response->isSuccessful() && \in_array($response->getStatusCode(), [200, 203, 300, 301, 410], true)) {
            $ttl = $this->computeTtl($response);
            if ($ttl !== null && $ttl >= 0) {
                // ttl === 0 (e.g. "Cache-Control: max-age=0") is a valid, if degenerate,
                // directive: store the entry, but it's immediately stale, so the very next
                // request revalidates it via If-None-Match/If-Modified-Since instead of
                // skipping storage altogether (which would turn it into an unconditional
                // miss every time and never actually revalidate).
                $this->store->set($key, [
                    'status'        => $response->getStatusCode(),
                    'headers'       => $response->getHeaders(),
                    'body'          => $response->getContent(),
                    'etag'          => $response->getHeader('ETag'),
                    'last_modified' => $response->getHeader('Last-Modified'),
                ], $ttl);
            }
        }

        return $response;
    }

    /**
     * @param  HttpResponseInterface $response
     * @return int|null Null when the response gives no usable freshness lifetime
     *                   (no max-age, no Expires) or explicitly forbids storage
     *                   (Cache-Control: no-store/private).
     */
    private function computeTtl(HttpResponseInterface $response): ?int
    {
        $cacheControl = \strtolower($response->getHeaderLine('Cache-Control'));

        if ($cacheControl !== '' && (\str_contains($cacheControl, 'no-store') || \str_contains($cacheControl, 'private'))) {
            return null;
        }

        if (\preg_match('/max-age=(\d+)/', $cacheControl, $m) === 1) {
            return \min($this->maxTtl, (int) $m[1]);
        }

        $expires = $response->getHeader('Expires');
        if ($expires !== null) {
            $ts = \strtotime($expires);
            if ($ts !== false) {
                return \min($this->maxTtl, \max(0, $ts - \time()));
            }
        }

        return null;
    }

    /**
     * @param  string $method
     * @param  string $url
     * @return string
     */
    private function cacheKey(string $method, string $url): string
    {
        return "{$method} {$url}";
    }
}
