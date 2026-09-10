<?php

namespace rafalmasiarek\DashboardKit\Utils;

/**
 * Generates and validates UUID v4 identifiers.
 *
 * @package rafalmasiarek\DashboardKit\Utils
 */
class Uuid
{
    /**
     * Generates a cryptographically random UUID v4.
     *
     * @return string UUID in xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx format.
     */
    public static function v4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Returns whether the given string is a valid UUID v4.
     *
     * @param  string $uuid
     * @return bool
     */
    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }
}
