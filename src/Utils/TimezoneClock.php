<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Utils;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Default implementation of the richer ClockInterface — defers entirely to
 * PHP's own default timezone, no configuration of its own.
 *
 * @package rafalmasiarek\DashboardKit\Utils
 */
final class TimezoneClock implements ClockInterface
{
    /**
     * {@inheritdoc}
     */
    public function tzId(): string
    {
        return date_default_timezone_get();
    }

    /**
     * {@inheritdoc}
     */
    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->tzId());
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
        return $this->now()->setTimezone(new DateTimeZone('UTC'));
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
        return $this->at('@' . $ts)->format(DATE_ATOM);
    }

    /**
     * {@inheritdoc}
     */
    public function formatAtom(DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->format(DATE_ATOM);
    }

    /**
     * {@inheritdoc}
     */
    public function formatSqlUtc(DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * {@inheritdoc}
     */
    public function formatMinuteKey(DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->format('YmdHi');
    }

    /**
     * {@inheritdoc}
     */
    public function formatMinuteKeyFromTimestamp(int $ts): string
    {
        return $this->formatMinuteKey($this->at('@' . $ts));
    }
}
