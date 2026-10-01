<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

use rafalmasiarek\DashboardKit\Dns\DnsQueryException;
use rafalmasiarek\DashboardKit\Dns\DnsResolverInterface;

/**
 * Consumes a "text/event-stream" (Server-Sent Events) endpoint, invoking a
 * callback for each parsed frame as it arrives.
 *
 * This is deliberately a separate, callback-based API rather than an extension
 * of HttpClientInterface/HttpResponseInterface: an SSE connection is long-lived
 * and delivered incrementally, which the rest of this package's lazy-but-whole
 * response model (CurlResponse: first access resolves everything in one go)
 * isn't built for. connect() blocks for the lifetime of the connection — until
 * the server closes it, a transport error occurs, or $onEvent returns false.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class EventSourceClient
{
    /**
     * @param DnsResolverInterface $dns Resolver used to pin the connection's target IP,
     *                                  same as CurlHttpClient.
     */
    public function __construct(
        private readonly DnsResolverInterface $dns,
    ) {
    }

    /**
     * @param  string                                   $url     Absolute URL of the event-stream endpoint.
     * @param  callable(ServerSentEvent): (bool|void)    $onEvent Called once per parsed frame. Returning
     *                                                             exactly `false` disconnects; any other
     *                                                             return value (including none) keeps the
     *                                                             connection open.
     * @param  array<string, mixed>                      $options Supports 'headers' and 'verify_peer' from
     *                                                             HttpClientInterface::request()'s shape,
     *                                                             plus 'last_event_id' (string) — sent as
     *                                                             the Last-Event-ID header, for resuming a
     *                                                             stream after a reconnect.
     * @return string|null The last "id:" value seen on the stream (useful for a caller-driven
     *                      reconnect loop setting 'last_event_id' on the next connect() call),
     *                      or null if none arrived.
     * @throws \RuntimeException On a connection failure (DNS, TCP, TLS) before any data arrived.
     */
    public function connect(string $url, callable $onEvent, array $options = []): ?string
    {
        $headers = (array) ($options['headers'] ?? []);
        $headers['Accept'] = 'text/event-stream';
        if (isset($options['last_event_id'])) {
            $headers['Last-Event-ID'] = (string) $options['last_event_id'];
        }

        $ch = \curl_init();

        \curl_setopt_array($ch, [
            \CURLOPT_URL            => $url,
            \CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
            \CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_peer'] ?? true),
            \CURLOPT_TIMEOUT        => 0, // no total-duration cap — a stream is meant to stay open
            \CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 10),
        ]);

        $host = \parse_url($url)['host'] ?? null;
        $resolvedIp = $this->resolveHostIp($host);
        if ($resolvedIp !== null && $host !== null && \filter_var($host, \FILTER_VALIDATE_IP) === false) {
            $resolve = $this->buildResolveOption($url, $host, $resolvedIp);
            if ($resolve !== null) {
                \curl_setopt($ch, \CURLOPT_RESOLVE, [$resolve]);
            }
        }

        $buffer = '';
        $lastId = null;
        $stop = false;

        \curl_setopt($ch, \CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use (&$buffer, &$lastId, &$stop, $onEvent): int {
            if ($stop) {
                return 0; // returning less than strlen($chunk) tells curl to abort the transfer.
            }

            $buffer .= $chunk;

            while (($frame = $this->extractFrame($buffer)) !== null) {
                [$raw, $buffer] = $frame;
                $event = $this->parseFrame($raw);
                if ($event === null) {
                    continue;
                }
                if ($event->id !== null) {
                    $lastId = $event->id;
                }

                if ($onEvent($event) === false) {
                    $stop = true;
                    return 0;
                }
            }

            return \strlen($chunk);
        });

        $ok = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = $errno !== 0 ? \curl_error($ch) : null;
        \curl_close($ch);

        if ($ok === false && !$stop && $error !== null) {
            throw new \RuntimeException("EventSourceClient connection to \"{$url}\" failed: {$error}");
        }

        return $lastId;
    }

    /**
     * Extracts the next complete, blank-line-terminated frame from $buffer, if any.
     *
     * @param  string $buffer
     * @return array{0: string, 1: string}|null [frame, remaining buffer], or null when
     *                                           no complete frame is available yet.
     */
    private function extractFrame(string $buffer): ?array
    {
        foreach (["\r\n\r\n", "\n\n"] as $terminator) {
            $pos = \strpos($buffer, $terminator);
            if ($pos !== false) {
                return [\substr($buffer, 0, $pos), \substr($buffer, $pos + \strlen($terminator))];
            }
        }
        return null;
    }

    /**
     * @param  string $raw One frame's worth of lines (no trailing blank line).
     * @return ServerSentEvent|null Null for a frame with no data line (e.g. a bare comment/retry frame).
     */
    private function parseFrame(string $raw): ?ServerSentEvent
    {
        $dataLines = [];
        $event = 'message';
        $id = null;
        $retry = null;

        foreach (\preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if ($line === '' || \str_starts_with($line, ':')) {
                continue; // blank or comment line
            }

            $sepPos = \strpos($line, ':');
            $field = $sepPos === false ? $line : \substr($line, 0, $sepPos);
            $value = $sepPos === false ? '' : \ltrim(\substr($line, $sepPos + 1), ' ');

            switch ($field) {
                case 'data':
                    $dataLines[] = $value;
                    break;
                case 'event':
                    $event = $value;
                    break;
                case 'id':
                    $id = $value;
                    break;
                case 'retry':
                    $retry = \ctype_digit($value) ? (int) $value : null;
                    break;
            }
        }

        if ($dataLines === [] && $id === null && $retry === null) {
            return null;
        }

        return new ServerSentEvent(\implode("\n", $dataLines), $event, $id, $retry);
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
     * @param  string $url
     * @param  string $host
     * @param  string $ip
     * @return string|null
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
