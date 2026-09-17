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
 * working HTTP call.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class CurlHttpClient implements HttpClientInterface
{
    /** @var float Default total request timeout, in seconds, when 'timeout' is not in $options. */
    private const DEFAULT_TIMEOUT = 10.0;

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
     * @param  array<string, mixed> $options See HttpClientInterface::request().
     * @return HttpResponse
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $url = $this->applyQuery($url, (array) ($options['query'] ?? []));

        [$requestBody, $headers] = $this->resolveBody($options);

        $ch = \curl_init();

        \curl_setopt_array($ch, [
            \CURLOPT_URL            => $url,
            \CURLOPT_CUSTOMREQUEST  => \strtoupper($method),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 20,
            \CURLOPT_TIMEOUT        => (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            \CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_peer'] ?? true),
            \CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
        ]);

        if ($requestBody !== null) {
            \curl_setopt($ch, \CURLOPT_POSTFIELDS, $requestBody);
        }

        if (isset($options['auth_basic'])) {
            $auth = $options['auth_basic'];
            \curl_setopt($ch, \CURLOPT_USERPWD, \is_array($auth) ? \implode(':', $auth) : (string) $auth);
        }

        $this->applyTlsOptions($ch, $options);

        $resolve = $this->buildResolveOption($url);
        if ($resolve !== null) {
            \curl_setopt($ch, \CURLOPT_RESOLVE, [$resolve]);
        }

        $responseHeaders = [];
        \curl_setopt($ch, \CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders): int {
            if (\str_starts_with($line, 'HTTP/')) {
                // A status line starts a new header block — with FOLLOWLOCATION this
                // fires once per redirect hop, so only the final block should survive.
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
     * Resolves the URL's host via the configured DNS resolver and builds a
     * CURLOPT_RESOLVE entry for it. Returns null when resolution isn't
     * possible or didn't produce an address — curl falls back to its own
     * resolution in that case.
     *
     * @param  string $url
     * @return string|null "host:port:ip", suitable for CURLOPT_RESOLVE.
     */
    private function buildResolveOption(string $url): ?string
    {
        $parts = \parse_url($url);
        $host = $parts['host'] ?? null;
        if ($host === null || \filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        try {
            $answer = $this->dns->resolveA($host);
        } catch (DnsQueryException) {
            return null;
        }

        if ($answer->records === []) {
            return null;
        }

        return "{$host}:{$port}:{$answer->records[0]}";
    }
}
