<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Http;

/**
 * Converts a symbolic domain error code into an HTTP status + human-readable message pair.
 *
 * Works with any resolver object that exposes:
 *   describe(string $code): array{message: string, http?: int}
 *
 * Fallback rules:
 *   HTTP:    resolver['http'] → 200 (if $ok and code starts with "OK_") → 400
 *   Message: $fallbackMessage → resolver['message'] → $code itself
 *
 * @package rafalmasiarek\DashboardKit\Http
 */
final class HttpMessagePicker
{
    /**
     * Resolve both the HTTP status and the human-readable message at once.
     *
     * @param  object $resolver        Any object with a describe(string): array method.
     * @param  string $code            Symbolic domain code (e.g. "OK_SENT", "EMAIL_INVALID").
     * @param  bool   $ok              True when the underlying operation succeeded.
     * @param  string $fallbackMessage Optional message provided by the caller; takes precedence over the descriptor.
     * @return array{http: int, message: string}
     */
    public static function pick(object $resolver, string $code, bool $ok, string $fallbackMessage = ''): array
    {
        $desc = self::describe($resolver, $code);

        $http = isset($desc['http']) && \is_int($desc['http'])
            ? (int) $desc['http']
            : ($ok && \str_starts_with($code, 'OK_') ? 200 : 400);

        $message = $fallbackMessage !== ''
            ? $fallbackMessage
            : (string) ($desc['message'] ?? $code);

        return ['http' => $http, 'message' => $message];
    }

    /**
     * Resolve only the HTTP status code.
     *
     * @param  object $resolver
     * @param  string $code
     * @param  bool   $ok
     * @return int
     */
    public static function pickHttp(object $resolver, string $code, bool $ok): int
    {
        return self::pick($resolver, $code, $ok)['http'];
    }

    /**
     * Resolve only the human-readable message.
     *
     * @param  object $resolver
     * @param  string $code
     * @param  bool   $ok
     * @param  string $fallbackMessage
     * @return string
     */
    public static function pickMessage(object $resolver, string $code, bool $ok, string $fallbackMessage = ''): string
    {
        return self::pick($resolver, $code, $ok, $fallbackMessage)['message'];
    }

    /**
     * Duck-typed describe() call with a safe fallback.
     *
     * @param  object $resolver
     * @param  string $code
     * @return array{message: string, http?: int}
     */
    private static function describe(object $resolver, string $code): array
    {
        if (\method_exists($resolver, 'describe')) {
            $raw = $resolver->describe($code);
            if (\is_array($raw) && isset($raw['message'])) {
                /** @var array{message: string, http?: int} */
                return $raw;
            }
        }
        return ['message' => $code];
    }
}
