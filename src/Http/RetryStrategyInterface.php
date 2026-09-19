<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Decides whether and how long to wait before retrying a request, given the
 * response RetryHttpClient just received (or the transport error it carries).
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
interface RetryStrategyInterface
{
    /**
     * @param  string       $method   HTTP method of the request that was just attempted.
     * @param  HttpResponse $response The response received (statusCode 0 and a non-null
     *                                error mean a transport-level failure, not an HTTP response).
     * @param  int          $attempt  1-based count of attempts made so far, including this one.
     * @return bool True to retry.
     */
    public function shouldRetry(string $method, HttpResponse $response, int $attempt): bool;

    /**
     * @param  HttpResponse $response The response that triggered the retry.
     * @param  int          $attempt  1-based count of attempts made so far, including this one.
     * @return int Milliseconds to wait before the next attempt.
     */
    public function getDelayMilliseconds(HttpResponse $response, int $attempt): int;
}
