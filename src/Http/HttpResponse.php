<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Result of an HTTP request made via HttpClientInterface.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final readonly class HttpResponse
{
    /**
     * @param int                        $statusCode HTTP status code. 0 when the request never reached the server.
     * @param array<string, list<string>> $headers   Response headers, lowercase names, all values per
     *                                                name preserved (e.g. multiple Set-Cookie headers).
     * @param string                     $body       Response body.
     * @param string|null                $error      curl error message, when the request failed at the transport level.
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public ?string $error = null,
    ) {
    }

    /**
     * @return bool True when the request completed and returned a 2xx status.
     */
    public function isSuccessful(): bool
    {
        return $this->error === null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Returns the first value of a header, or null when absent.
     *
     * @param  string $name Header name, case-insensitive.
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[\strtolower($name)][0] ?? null;
    }

    /**
     * Returns all values of a header, comma-joined (PSR-7 convention), or an empty string when absent.
     *
     * @param  string $name Header name, case-insensitive.
     * @return string
     */
    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->headers[\strtolower($name)] ?? []);
    }

    /**
     * Decodes the body as JSON.
     *
     * @return mixed Decoded value, or null when the body isn't valid JSON.
     */
    public function json(): mixed
    {
        return \json_decode($this->body, true);
    }
}
