<?php

namespace rafalmasiarek\DashboardKit\Utils;

/**
 * Configurable password strength scorer returning a score in the range 0–4.
 *
 * Rules are named arrays merged recursively with DEFAULT_RULES.
 * Pass only the keys you want to override — everything else inherits the default.
 *
 * Scoring pipeline:
 *   1. Forbidden characters → instant score 0 (no further evaluation).
 *   2. Positive bonuses: min_length, diversity, digit_ratio, special_ratio.
 *   3. Soft penalties: no_repeats, no_sequences (subtracted from bonus total).
 *   4. final = max(0, earned − penalties) / max_possible × 5, clamped to 0–4.
 *
 * The companion JavaScript in register.twig mirrors this logic exactly using the
 * resolved rule set serialised as JSON, so client-side and server-side always agree.
 *
 * @package rafalmasiarek\DashboardKit\Utils
 */
class PasswordStrength
{
    /** @var array<int, string> Human-readable label for each score 0–4. */
    public const LABELS = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];

    /**
     * Default rule set.
     *
     * Rule keys:
     *   min_length      — bonus when password length >= value
     *   diversity       — bonus per character class present (lower, upper, digit, special)
     *   digit_ratio     — bonus when digit ratio is within [min_ratio, max_ratio]
     *   special_ratio   — bonus when special-character ratio >= min_ratio
     *   forbidden_chars — instant score 0 if any listed character is found
     *   no_repeats      — penalty when N+ consecutive identical characters are present
     *   no_sequences    — penalty when an ascending/descending run of given length is present
     *
     * @var array<string, array<string, mixed>>
     */
    public const DEFAULT_RULES = [
        'min_length' => [
            'enabled' => true,
            'value'   => 8,
            'points'  => 10,
        ],
        'diversity' => [
            'enabled'         => true,
            'points_per_type' => 5,
        ],
        'digit_ratio' => [
            'enabled'   => true,
            'min_ratio' => 0.10,
            'max_ratio' => 0.50,
            'points'    => 10,
        ],
        'special_ratio' => [
            'enabled'   => true,
            'min_ratio' => 0.05,
            'points'    => 10,
        ],
        'forbidden_chars' => [
            'enabled' => true,
            'chars'   => [],
        ],
        'no_repeats' => [
            'enabled'    => true,
            'max_repeat' => 2,
            'penalty'    => 15,
        ],
        'no_sequences' => [
            'enabled' => true,
            'length'  => 3,
            'penalty' => 15,
        ],
    ];

    /**
     * Returns the strength score for the given password.
     *
     * @param  string                        $password      Plain-text password.
     * @param  array<string, array<string, mixed>> $ruleOverrides Partial overrides merged into DEFAULT_RULES.
     * @return int                                           Score 0–4.
     */
    public static function score(string $password, array $ruleOverrides = []): int
    {
        $rules = empty($ruleOverrides)
            ? self::DEFAULT_RULES
            : array_replace_recursive(self::DEFAULT_RULES, $ruleOverrides);

        $len = strlen($password);

        if ($rules['forbidden_chars']['enabled'] ?? true) {
            foreach ((array) ($rules['forbidden_chars']['chars'] ?? []) as $char) {
                if (str_contains($password, (string) $char)) {
                    return 0;
                }
            }
        }

        $earned = 0;
        $max    = 0;

        if ($rules['min_length']['enabled'] ?? true) {
            $pts = (int) ($rules['min_length']['points'] ?? 10);
            $max += $pts;
            if ($len >= (int) ($rules['min_length']['value'] ?? 8)) {
                $earned += $pts;
            }
        }

        if ($rules['diversity']['enabled'] ?? true) {
            $ppt  = (int) ($rules['diversity']['points_per_type'] ?? 5);
            $max += $ppt * 4;
            if (preg_match('/[a-z]/', $password))         $earned += $ppt;
            if (preg_match('/[A-Z]/', $password))         $earned += $ppt;
            if (preg_match('/\d/', $password))            $earned += $ppt;
            if (preg_match('/[^a-zA-Z0-9]/', $password)) $earned += $ppt;
        }

        if (($rules['digit_ratio']['enabled'] ?? true) && $len > 0) {
            $pts      = (int)   ($rules['digit_ratio']['points']    ?? 10);
            $minRatio = (float) ($rules['digit_ratio']['min_ratio'] ?? 0.10);
            $maxRatio = (float) ($rules['digit_ratio']['max_ratio'] ?? 0.50);
            $max += $pts;
            $ratio = preg_match_all('/\d/', $password) / $len;
            if ($ratio >= $minRatio && $ratio <= $maxRatio) {
                $earned += $pts;
            }
        }

        if (($rules['special_ratio']['enabled'] ?? true) && $len > 0) {
            $pts      = (int)   ($rules['special_ratio']['points']    ?? 10);
            $minRatio = (float) ($rules['special_ratio']['min_ratio'] ?? 0.05);
            $max += $pts;
            $ratio = preg_match_all('/[^a-zA-Z0-9]/', $password) / $len;
            if ($ratio >= $minRatio) {
                $earned += $pts;
            }
        }

        if ($max <= 0) {
            return 0;
        }

        $penalty = 0;

        if ($rules['no_repeats']['enabled'] ?? true) {
            $maxRepeat = (int) ($rules['no_repeats']['max_repeat'] ?? 2);
            $pen       = (int) ($rules['no_repeats']['penalty']    ?? 15);
            if (preg_match('/(.)\1{' . $maxRepeat . ',}/', $password)) {
                $penalty += $pen;
            }
        }

        if ($rules['no_sequences']['enabled'] ?? true) {
            $seqLen = (int) ($rules['no_sequences']['length']  ?? 3);
            $pen    = (int) ($rules['no_sequences']['penalty'] ?? 15);
            if (self::hasSequence($password, $seqLen)) {
                $penalty += $pen;
            }
        }

        $effective = max(0, $earned - $penalty);

        return min(4, (int) floor($effective / $max * 5));
    }

    /**
     * Returns whether the password contains an ascending or descending character
     * sequence of at least the given length (case-insensitive).
     *
     * Examples (length=3): "abc", "XYZ", "321", "cba" all match.
     *
     * @param  string $password
     * @param  int    $length   Minimum run length to flag.
     * @return bool
     */
    private static function hasSequence(string $password, int $length): bool
    {
        $pwd = strtolower($password);
        $len = strlen($pwd);

        for ($i = 0; $i <= $len - $length; $i++) {
            $asc  = true;
            $desc = true;

            for ($j = 1; $j < $length; $j++) {
                $diff = ord($pwd[$i + $j]) - ord($pwd[$i + $j - 1]);
                if ($diff !== 1)  $asc  = false;
                if ($diff !== -1) $desc = false;
            }

            if ($asc || $desc) {
                return true;
            }
        }

        return false;
    }
}
