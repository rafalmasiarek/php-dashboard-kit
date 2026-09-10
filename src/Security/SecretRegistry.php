<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Security;

/**
 * Central registry of sensitive values to be redacted from messages and stack traces.
 *
 * @package rafalmasiarek\DashboardKit\Security
 */
final class SecretRegistry
{
    /**
     * Registered secret values.
     *
     * @var array<int, string>
     */
    private static array $values = [];

    /**
     * Minimum character length for a value to qualify as a secret.
     *
     * @var int
     */
    private const MIN_LEN = 6;

    private function __construct() {}

    /**
     * Register a single secret value.
     *
     * No-op when the value is null, empty, or shorter than MIN_LEN.
     *
     * @param  string|null $value
     * @return void
     */
    public static function addValue(?string $value): void
    {
        if (!\is_string($value)) {
            return;
        }
        $v = \trim($value);
        if ($v === '' || \mb_strlen($v) < self::MIN_LEN) {
            return;
        }
        if (!\in_array($v, self::$values, true)) {
            self::$values[] = $v;
        }
    }

    /**
     * Register multiple secret values at once.
     *
     * @param  iterable<string> $values
     * @return void
     */
    public static function addValues(iterable $values): void
    {
        foreach ($values as $v) {
            self::addValue(\is_string($v) ? $v : null);
        }
    }

    /**
     * Recursively scan a config array and register values whose key path looks sensitive.
     *
     * Matched key path segments (case-insensitive):
     *   secret, password, passwd, pwd, token, api_key, api-key, smtp.*password,
     *   _key, _pass, _hash, _salt, _pem, _kek, credential
     *
     * @param  array<string, mixed> $config
     * @return void
     */
    public static function primeFromConfig(array $config): void
    {
        $pattern = '/(secret|password|passwd|pwd|token|api[_-]?key|smtp.*password|_key|_pass|_hash|_salt|_pem|_kek|credential)/i';

        $walker = static function (mixed $node, string $keyPath) use (&$walker, $pattern): void {
            if (\is_array($node)) {
                foreach ($node as $k => $v) {
                    $kp = $keyPath === '' ? (string) $k : $keyPath . '.' . (string) $k;
                    $walker($v, $kp);
                }
                return;
            }
            if (\preg_match($pattern, $keyPath)) {
                if (\is_string($node)) {
                    self::addValue($node);
                } elseif (\is_numeric($node)) {
                    self::addValue((string) $node);
                }
            }
        };

        $walker($config, '');
    }

    /**
     * Replace all registered secret values in a string with "****".
     *
     * Returns the original string unchanged when no secrets are registered.
     *
     * @param  string $message
     * @return string
     */
    public static function redactValues(string $message): string
    {
        if (self::$values === []) {
            return $message;
        }

        $parts = [];
        foreach (self::$values as $v) {
            $parts[] = \preg_quote($v, '/');
        }
        $regex = '/(' . \implode('|', $parts) . ')/u';

        return (string) \preg_replace($regex, '****', $message);
    }

    /**
     * Scan $_ENV and $_SERVER for keys that look sensitive and register their values.
     *
     * Uses the same key pattern as the Whoops error-page blacklist in Dashboard.
     *
     * @return void
     */
    public static function primeFromEnv(): void
    {
        $pattern = '/(SECRET|TOKEN|PASSWORD|PASSWD|PWD|_PASS|_KEY|_HASH|_SALT|_PEM|_KEK|CREDENTIAL|API[_-]?KEY)/i';
        foreach (\array_merge($_ENV, $_SERVER) as $envKey => $envVal) {
            if (\preg_match($pattern, (string) $envKey) && \is_string($envVal)) {
                self::addValue($envVal);
            }
        }
    }

    /**
     * Clear all registered values (primarily useful in tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$values = [];
    }
}
