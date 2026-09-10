<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Log;

/**
 * Sanitizes request parameters before writing them to access logs.
 *
 * Rules applied to every field key (case-insensitive prefix/substring match):
 *   - Sensitive fields (password, secret, token, …) → replaced with "***"
 *   - Identity fields (email, login, username) → AES-256-CBC encrypted with APP_KEY
 *   - All other fields → logged as-is
 *
 * Encryption format: "enc:<base64(iv + ciphertext)>"
 * Decrypt with: openssl_decrypt(base64_decode(substr($v, 4)), 'aes-256-cbc', $key, iv...)
 *
 * Security model: safe when log storage and APP_KEY are separated.
 * Passwords are never stored — not even encrypted.
 *
 * @package rafalmasiarek\DashboardKit\Log
 */
final class RequestLogSanitizer
{
    /** @var string[] Field name substrings that must always be masked. */
    private const MASK_PATTERNS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'auth', 'credential', 'private', 'cvv', 'pin',
    ];

    /** @var string[] Field name substrings whose values should be encrypted. */
    private const ENCRYPT_PATTERNS = ['email', 'login', 'username', 'user_name'];

    /** @var string AES cipher used for identity field encryption. */
    private const CIPHER = 'aes-256-cbc';

    /** @var string Raw binary encryption key (32 bytes). */
    private readonly string $key;

    /**
     * @param string $appKey Hex-encoded APP_KEY from environment (min 32 hex chars = 16 bytes).
     */
    public function __construct(string $appKey)
    {
        $this->key = \substr(\hex2bin(\str_pad($appKey, 64, '0')), 0, 32);
    }

    /**
     * Sanitizes a flat key-value array of request parameters.
     *
     * Nested arrays (e.g. from multipart forms) are JSON-encoded before sanitizing.
     *
     * @param  array<string, mixed> $params Raw request parameters.
     * @return array<string, string> Sanitized parameters safe for logging.
     */
    public function sanitize(array $params): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            $keyLower = \strtolower((string) $key);
            $scalar   = \is_scalar($value) ? (string) $value : \json_encode($value, \JSON_UNESCAPED_UNICODE);

            if ($scalar === '' || $scalar === null || $scalar === false) {
                continue;
            }

            if ($this->matches($keyLower, self::MASK_PATTERNS)) {
                $result[$key] = '***';
                continue;
            }

            if ($this->matches($keyLower, self::ENCRYPT_PATTERNS)) {
                $result[$key] = $this->encrypt((string) $scalar);
                continue;
            }

            $result[$key] = (string) $scalar;
        }

        return $result;
    }

    /**
     * Encrypts a plaintext value with AES-256-CBC.
     *
     * Returns "enc:<base64(iv + ciphertext)>" or the original value on failure.
     *
     * @param  string $value Plaintext to encrypt.
     * @return string
     */
    public function encrypt(string $value): string
    {
        $ivLen = \openssl_cipher_iv_length(self::CIPHER);
        $iv    = \openssl_random_pseudo_bytes($ivLen);
        $cipher = \openssl_encrypt($value, self::CIPHER, $this->key, \OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return $value;
        }

        return 'enc:' . \base64_encode($iv . $cipher);
    }

    /**
     * Decrypts a value previously encrypted by encrypt().
     *
     * @param  string $value  Encrypted string in "enc:<base64>" format.
     * @param  string $appKey APP_KEY used during encryption.
     * @return string|null    Decrypted plaintext, or null on failure.
     */
    public static function decrypt(string $value, string $appKey): ?string
    {
        if (!\str_starts_with($value, 'enc:')) {
            return null;
        }

        $key    = \substr(\hex2bin(\str_pad($appKey, 64, '0')), 0, 32);
        $raw    = \base64_decode(\substr($value, 4), true);

        if ($raw === false) {
            return null;
        }

        $ivLen = \openssl_cipher_iv_length(self::CIPHER);
        $iv    = \substr($raw, 0, $ivLen);
        $cipher = \substr($raw, $ivLen);

        $plain = \openssl_decrypt($cipher, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    /**
     * Checks whether any pattern appears as a substring of the subject.
     *
     * @param  string   $subject  Lowercase field name.
     * @param  string[] $patterns List of substrings to match.
     * @return bool
     */
    private function matches(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (\str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
