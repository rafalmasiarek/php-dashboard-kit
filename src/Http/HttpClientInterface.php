<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Minimal HTTP client contract, option shape inspired by Symfony HttpClient / Guzzle.
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
interface HttpClientInterface
{
    /**
     * Performs an HTTP request.
     *
     * Supported $options keys:
     *  - headers     (array<string,string|list<string>>) : request headers; an array value sends
     *                                                        the same header name multiple times
     *  - query       (array<string,mixed>) : appended to the URL as a query string
     *  - body        (string|array<string,mixed>) : request body. A string is sent as-is. An array is
     *                                         sent application/x-www-form-urlencoded, unless it contains
     *                                         a \CURLFile/\CURLStringFile value, in which case it's sent
     *                                         multipart/form-data (curl sets its own boundary + Content-Type).
     *  - json        (mixed)               : JSON-encoded request body; sets Content-Type: application/json
     *                                         (ignored if 'body' is also set)
     *  - timeout     (float)               : total request timeout in seconds
     *  - auth_basic  (string|array{0:string,1:string}) : HTTP Basic Auth, as "user:pass" or [user, pass]
     *  - verify_peer (bool)                : verify the server's TLS certificate (default: true)
     *  - local_cert  (string)               : client certificate path, for mTLS
     *  - local_pk    (string)               : client private key path, when separate from local_cert
     *  - passphrase  (string)               : client private key passphrase
     *  - cafile      (string)               : custom CA bundle path
     *
     * @param  string               $method  HTTP method (GET, POST, ...).
     * @param  string               $url     Absolute URL.
     * @param  array<string, mixed> $options See above.
     * @return HttpResponse
     */
    public function request(string $method, string $url, array $options = []): HttpResponse;
}
