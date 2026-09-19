<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

use rafalmasiarek\DashboardKit\Dns\DnsQueryException;
use rafalmasiarek\DashboardKit\Dns\DnsResolverInterface;

/**
 * curl-based HttpClientInterface implementation that resolves hostnames via an
 * injected DnsResolverInterface and pins the result with CURLOPT_RESOLVE,
 * instead of letting curl perform its own DNS resolution.
 *
 * DNS resolution failure never fails the request outright — it just falls
 * back to curl's own resolution, since a resolver hiccup (e.g. a custom
 * resolver being temporarily unreachable) should not break an otherwise
 * working HTTP call. The one exception is 'block_private_network' (see
 * request()) — there, a resolution that can't be confirmed safe is treated
 * as unsafe rather than silently falling through to an unchecked connection.
 *
 * Redirects are followed by this class itself, one hop at a time, rather
 * than via CURLOPT_FOLLOWLOCATION — this is what lets it strip credential
 * headers when a redirect crosses origins, and re-run the private-network
 * check (and DNS pinning) on every hop instead of only the first one.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class CurlHttpClient implements HttpClientInterface
{
    /** @var float Default total request timeout, in seconds, when 'timeout' is not in $options. */
    private const DEFAULT_TIMEOUT = 10.0;

    /** @var int Maximum number of redirect hops followed before giving up and returning the last redirect response as-is. */
    private const MAX_REDIRECTS = 20;

    /** @var list<string> Header names (lowercase) never forwarded to a different origin on redirect. */
    private const CREDENTIAL_HEADERS = ['authorization', 'proxy-authorization', 'cookie'];

    /**
     * @param DnsResolverInterface $dns Resolver used to pin the request's target IP.
     */
    public function __construct(
        private readonly DnsResolverInterface $dns,
    ) {
    }

    /**
     * @param  string               $method  HTTP method (GET, POST, ...).
     * @param  string               $url     Absolute URL.
     * @param  array<string, mixed> $options See HttpClientInterface::request(). Two extra keys
     *                                        beyond the base interface: 'follow_redirects' (bool,
     *                                        default true) and 'block_private_network' (bool,
     *                                        default false) — refuse to connect when the target
     *                                        resolves to a private/reserved-range address, checked
     *                                        again on every redirect hop. Intended for requests to
     *                                        externally-supplied URLs (e.g. user-submitted links a
     *                                        cron job probes), not for the app-wide default client.
     * @return HttpResponse
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $url = $this->applyQuery($url, (array) ($options['query'] ?? []));
        unset($options['query']);

        $followRedirects = (bool) ($options['follow_redirects'] ?? true);
        unset($options['follow_redirects']);

        $currentMethod = \strtoupper($method);
        $currentUrl = $url;
        $currentOptions = $options;

        for ($redirectCount = 0; ; $redirectCount++) {
            $response = $this->doRequest($currentMethod, $currentUrl, $currentOptions);

            if (!$followRedirects || $redirectCount >= self::MAX_REDIRECTS) {
                return $response;
            }

            if (!\in_array($response->statusCode, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = $response->getHeader('Location');
            if ($location === null || $location === '') {
                return $response;
            }

            $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);

            if ($this->isCrossOrigin($currentUrl, $nextUrl)) {
                $currentOptions['headers'] = $this->stripCredentialHeaders((array) ($currentOptions['headers'] ?? []));
                unset($currentOptions['auth_basic']);
            }

            // 301/302/303 historically downgrade a non-GET/HEAD request to GET and drop
            // its body (matches curl's own default FOLLOWLOCATION behavior, and browsers).
            // 307/308 preserve method and body as-is.
            if (
                \in_array($response->statusCode, [301, 302, 303], true)
                && !\in_array($currentMethod, ['GET', 'HEAD'], true)
            ) {
                $currentMethod = 'GET';
                unset($currentOptions['body'], $currentOptions['json']);
            }

            $currentUrl = $nextUrl;
        }
    }

    /**
     * Executes a single HTTP request (no redirect following).
     *
     * @param  string               $method
     * @param  string               $url
     * @param  array<string, mixed> $options
     * @return HttpResponse
     */
    private function doRequest(string $method, string $url, array $options): HttpResponse
    {
        [$requestBody, $headers] = $this->resolveBody($options);

        $ch = \curl_init();

        \curl_setopt_array($ch, [
            \CURLOPT_URL            => $url,
            \CURLOPT_CUSTOMREQUEST  => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            \CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_peer'] ?? true),
            \CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
        ]);

        if ($method === 'HEAD') {
            // Without this, curl still expects a body sized per Content-Length a GET
            // to the same URL would return, and reports a transport error when the
            // server correctly sends none — even though the headers we actually want
            // (e.g. Last-Modified) arrive fine.
            \curl_setopt($ch, \CURLOPT_NOBODY, true);
        }

        if ($requestBody !== null) {
            \curl_setopt($ch, \CURLOPT_POSTFIELDS, $requestBody);
        }

        if (isset($options['auth_basic'])) {
            $auth = $options['auth_basic'];
            \curl_setopt($ch, \CURLOPT_USERPWD, \is_array($auth) ? \implode(':', $auth) : (string) $auth);
        }

        $this->applyTlsOptions($ch, $options);

        $host = \parse_url($url)['host'] ?? null;
        $resolvedIp = $this->resolveHostIp($host);

        if ((bool) ($options['block_private_network'] ?? false) && $host !== null) {
            $ipToCheck = $resolvedIp ?? $host;
            if ($this->isPrivateOrReservedIp($ipToCheck)) {
                \curl_close($ch);
                return new HttpResponse(
                    statusCode: 0,
                    headers: [],
                    body: '',
                    error: "Blocked request to \"{$host}\": target resolves to a private or reserved network address.",
                );
            }
        }

        if ($resolvedIp !== null && $host !== null && \filter_var($host, \FILTER_VALIDATE_IP) === false) {
            $resolve = $this->buildResolveOption($url, $host, $resolvedIp);
            if ($resolve !== null) {
                \curl_setopt($ch, \CURLOPT_RESOLVE, [$resolve]);
            }
        }

        $responseHeaders = [];
        \curl_setopt($ch, \CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders): int {
            if (\str_starts_with($line, 'HTTP/')) {
                $responseHeaders = [];
                return \strlen($line);
            }

            $parts = \explode(':', $line, 2);
            if (\count($parts) === 2) {
                $responseHeaders[\strtolower(\trim($parts[0]))][] = \trim($parts[1]);
            }
            return \strlen($line);
        });

        $responseBody = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = $errno !== 0 ? \curl_error($ch) : null;
        $statusCode = $errno === 0 ? (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE) : 0;

        \curl_close($ch);

        return new HttpResponse(
            statusCode: $statusCode,
            headers: $responseHeaders,
            body: \is_string($responseBody) ? $responseBody : '',
            error: $error,
        );
    }

    /**
     * Resolves the request body and any headers implied by it (e.g. Content-Type for JSON).
     *
     * A 'body' array without any \CURLFile/\CURLStringFile value is form-urlencoded.
     * One containing such a value is passed to curl as-is, which then sends it as
     * multipart/form-data with its own boundary and Content-Type — no header is
     * added here in that case, since ours would conflict with curl's boundary.
     *
     * @param  array<string, mixed> $options
     * @return array{0: string|array<string,mixed>|null, 1: array<string, string|list<string>>}
     */
    private function resolveBody(array $options): array
    {
        $headers = (array) ($options['headers'] ?? []);

        if (isset($options['body'])) {
            $body = $options['body'];

            if (\is_array($body)) {
                if ($this->hasFileParts($body)) {
                    return [$body, $headers];
                }
                $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
                return [\http_build_query($body), $headers];
            }

            return [(string) $body, $headers];
        }

        if (\array_key_exists('json', $options)) {
            $encoded = \json_encode($options['json']);
            $headers['Content-Type'] ??= 'application/json';
            return [$encoded !== false ? $encoded : null, $headers];
        }

        return [null, $headers];
    }

    /**
     * @param  array<string, mixed> $body
     * @return bool True when any value is a file part (multipart/form-data upload).
     */
    private function hasFileParts(array $body): bool
    {
        foreach ($body as $value) {
            if ($value instanceof \CURLFile || $value instanceof \CURLStringFile) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array<string, string|list<string>> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            foreach ((array) $value as $singleValue) {
                $lines[] = "{$name}: {$singleValue}";
            }
        }
        return $lines;
    }

    /**
     * Removes Authorization/Proxy-Authorization/Cookie headers (case-insensitive
     * name match) — called when a redirect crosses origins, so credentials meant
     * for the original host are never sent to wherever it redirected to.
     *
     * @param  array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    private function stripCredentialHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (\in_array(\strtolower((string) $name), self::CREDENTIAL_HEADERS, true)) {
                unset($headers[$name]);
            }
        }
        return $headers;
    }

    /**
     * Resolves a redirect's Location header against the URL it came from.
     * Handles absolute URLs, protocol-relative ("//host/path"), absolute-path
     * ("/path"), and relative paths (resolved against the base URL's directory,
     * with "." and ".." segments collapsed).
     *
     * @param  string $baseUrl
     * @param  string $location
     * @return string
     */
    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (\preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $base = \parse_url($baseUrl);
        if ($base === false) {
            return $location;
        }

        $scheme = $base['scheme'] ?? 'http';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $authority = $scheme . '://' . $host . $port;

        if (\str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (\str_starts_with($location, '/')) {
            return $authority . $this->normalizePath($location);
        }

        $basePath = $base['path'] ?? '/';
        $slashPos = \strrpos($basePath, '/');
        $baseDir = $slashPos !== false ? \substr($basePath, 0, $slashPos + 1) : '/';

        return $authority . $this->normalizePath($baseDir . $location);
    }

    /**
     * Collapses "." and ".." path segments.
     *
     * @param  string $path
     * @return string
     */
    private function normalizePath(string $path): string
    {
        $segments = \explode('/', $path);
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                \array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        $normalized = '/' . \implode('/', $out);
        if (\str_ends_with($path, '/') && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }

    /**
     * @param  string $urlA
     * @param  string $urlB
     * @return bool True when scheme, host, or port differ between the two URLs.
     *              Unparseable input is treated as cross-origin (fail safe).
     */
    private function isCrossOrigin(string $urlA, string $urlB): bool
    {
        $a = \parse_url($urlA);
        $b = \parse_url($urlB);
        if ($a === false || $b === false) {
            return true;
        }

        $schemeA = \strtolower($a['scheme'] ?? '');
        $schemeB = \strtolower($b['scheme'] ?? '');
        $hostA = \strtolower($a['host'] ?? '');
        $hostB = \strtolower($b['host'] ?? '');
        $portA = $a['port'] ?? ($schemeA === 'https' ? 443 : 80);
        $portB = $b['port'] ?? ($schemeB === 'https' ? 443 : 80);

        return $schemeA !== $schemeB || $hostA !== $hostB || $portA !== $portB;
    }

    /**
     * Applies client-certificate (mTLS) options, when provided.
     *
     * Supported $options keys: local_cert (client cert path), local_pk (private
     * key path, when separate from the cert), passphrase (private key
     * passphrase), cafile (custom CA bundle path).
     *
     * @param  \CurlHandle          $ch
     * @param  array<string, mixed> $options
     * @return void
     */
    private function applyTlsOptions(\CurlHandle $ch, array $options): void
    {
        if (isset($options['local_cert'])) {
            \curl_setopt($ch, \CURLOPT_SSLCERT, (string) $options['local_cert']);
        }
        if (isset($options['local_pk'])) {
            \curl_setopt($ch, \CURLOPT_SSLKEY, (string) $options['local_pk']);
        }
        if (isset($options['passphrase'])) {
            \curl_setopt($ch, \CURLOPT_SSLKEYPASSWD, (string) $options['passphrase']);
        }
        if (isset($options['cafile'])) {
            \curl_setopt($ch, \CURLOPT_CAINFO, (string) $options['cafile']);
        }
    }

    /**
     * Appends query parameters to a URL.
     *
     * @param  string              $url
     * @param  array<string,mixed> $query
     * @return string
     */
    private function applyQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $separator = \str_contains($url, '?') ? '&' : '?';
        return $url . $separator . \http_build_query($query);
    }

    /**
     * Resolves a host to the IP that will actually be connected to: the host
     * itself when it's already a literal IP, otherwise the first address the
     * configured DNS resolver returns for it. Returns null when the host is
     * absent or resolution fails — callers fall back to curl's own resolution
     * in that case, except where 'block_private_network' is set (see request()),
     * where a null result is treated as unsafe rather than silently allowed.
     *
     * @param  string|null $host
     * @return string|null
     */
    private function resolveHostIp(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        try {
            $answer = $this->dns->resolve($host);
        } catch (DnsQueryException) {
            return null;
        }

        return $answer->records[0] ?? null;
    }

    /**
     * @param  string $ip Candidate IPv4/IPv6 address (or a hostname that failed to resolve).
     * @return bool True when $ip is not a valid public, routable address — this
     *              includes private ranges (RFC 1918, ULA, ...), loopback, link-local,
     *              reserved ranges, and anything that isn't a valid IP at all (e.g. an
     *              unresolved hostname passed through), so unresolvable input fails closed.
     */
    private function isPrivateOrReservedIp(string $ip): bool
    {
        return \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Builds a CURLOPT_RESOLVE entry pinning $host to an already-resolved $ip.
     *
     * @param  string $url
     * @param  string $host
     * @param  string $ip
     * @return string|null "host:port:ip" (IPv6 addresses bracketed).
     */
    private function buildResolveOption(string $url, string $host, string $ip): ?string
    {
        $scheme = \parse_url($url)['scheme'] ?? 'http';
        $port = \parse_url($url)['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            $ip = "[{$ip}]";
        }

        return "{$host}:{$port}:{$ip}";
    }
}
