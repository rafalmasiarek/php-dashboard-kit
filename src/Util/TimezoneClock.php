<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Util;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Default implementation of the richer ClockInterface.
 *
 * Timezone resolution order:
 *  1) $configuredTzId (constructor argument)
 *  2) PHP default timezone (php.ini / date_default_timezone_set)
 *  3) $fallbackTzId (default: UTC)
 *
 * @package rafalmasiarek\DashboardKit\Util
 */
final class TimezoneClock implements ClockInterface
{
    /** @var string */
    private string $configuredTzId;

    /** @var string */
    private string $fallbackTzId;

    /** @var array<string, DateTimeZone> */
    private array $cache = [];

    /**
     * @param string $configuredTzId Timezone identifier to prefer (e.g. 'Europe/Warsaw').
     * @param string $fallbackTzId   Fallback when configured and PHP defaults are invalid.
     */
    public function __construct(string $configuredTzId = '', string $fallbackTzId = 'UTC')
    {
        $this->configuredTzId = trim($configuredTzId);
        $this->fallbackTzId   = trim($fallbackTzId) !== '' ? trim($fallbackTzId) : 'UTC';
    }

    /**
     * {@inheritdoc}
     */
    public function tzId(): string
    {
        foreach ([$this->configuredTzId, (string) date_default_timezone_get(), $this->fallbackTzId] as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            try {
                new DateTimeZone($id);
                return $id;
            } catch (Throwable) {
                // try next candidate
            }
        }
        return 'UTC';
    }

    /**
     * {@inheritdoc}
     */
    public function tz(): DateTimeZone
    {
        $id = $this->tzId();
        return $this->cache[$id] ??= new DateTimeZone($id);
    }

    /**
     * {@inheritdoc}
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->tz());
    }

    /**
     * {@inheritdoc}
     */
    public function nowUtc(): DateTimeImmutable
    {
        return $this->now()->setTimezone($this->utcTz());
    }

    /**
     * {@inheritdoc}
     */
    public function at(string $timeStr): DateTimeImmutable
    {
        return new DateTimeImmutable($timeStr, $this->tz());
    }

    /**
     * {@inheritdoc}
     */
    public function fromInterface(DateTimeInterface $dt): DateTimeImmutable
    {
        return $this->at('@' . $dt->getTimestamp())->setTimezone($this->tz());
    }

    /**
     * {@inheritdoc}
     */
    public function formatAtomFromTimestamp(int $ts): string
    {
        return $this->at('@' . $ts)->setTimezone($this->tz())->format(DATE_ATOM);
    }

    /**
     * {@inheritdoc}
     */
    public function formatAtom(DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone($this->tz())->format(DATE_ATOM);
    }

    /**
     * {@inheritdoc}
     */
    public function formatSqlUtc(DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone($this->utcTz())->format('Y-m-d H:i:s');
    }

    /**
     * {@inheritdoc}
     */
    public function formatMinuteKey(DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone($this->tz())->format('YmdHi');
    }

    /**
     * {@inheritdoc}
     */
    public function formatMinuteKeyFromTimestamp(int $ts): string
    {
        return $this->formatMinuteKey($this->at('@' . $ts));
    }

    /**
     * Returns the cached UTC timezone instance.
     *
     * @return DateTimeZone
     */
    private function utcTz(): DateTimeZone
    {
        return $this->cache['UTC'] ??= new DateTimeZone('UTC');
    }
}
