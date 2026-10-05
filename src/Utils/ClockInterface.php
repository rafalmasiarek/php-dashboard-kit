<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Utils;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * Timezone-aware clock contract, extending PSR-20 with formatting/timezone helpers.
 *
 * @package rafalmasiarek\DashboardKit\Utils
 */
interface ClockInterface extends PsrClockInterface
{
    /**
     * Resolves the effective timezone identifier.
     *
     * @return string
     */
    public function tzId(): string;

    /**
     * Returns the effective DateTimeZone instance.
     *
     * @return DateTimeZone
     */
    public function tz(): DateTimeZone;

    /**
     * Returns the current time in UTC.
     *
     * @return DateTimeImmutable
     */
    public function nowUtc(): DateTimeImmutable;

    /**
     * Creates a DateTimeImmutable for the given time string in this clock's timezone.
     *
     * @param  string $timeStr
     * @return DateTimeImmutable
     */
    public function at(string $timeStr): DateTimeImmutable;

    /**
     * Converts an arbitrary DateTimeInterface into an immutable instance in this clock's timezone.
     *
     * @param  DateTimeInterface $dt
     * @return DateTimeImmutable
     */
    public function fromInterface(DateTimeInterface $dt): DateTimeImmutable;

    /**
     * Formats a unix timestamp as DATE_ATOM in this clock's timezone.
     *
     * @param  int $ts Unix timestamp.
     * @return string
     */
    public function formatAtomFromTimestamp(int $ts): string;

    /**
     * Formats a DateTimeInterface as DATE_ATOM in this clock's timezone.
     *
     * @param  DateTimeInterface $dt
     * @return string
     */
    public function formatAtom(DateTimeInterface $dt): string;

    /**
     * Formats a DateTimeInterface as SQL DATETIME in UTC ("Y-m-d H:i:s").
     *
     * @param  DateTimeInterface $dt
     * @return string
     */
    public function formatSqlUtc(DateTimeInterface $dt): string;

    /**
     * Returns a minute slot key (YmdHi) for a DateTimeInterface in this clock's timezone.
     *
     * @param  DateTimeInterface $dt
     * @return string
     */
    public function formatMinuteKey(DateTimeInterface $dt): string;

    /**
     * Returns a minute slot key (YmdHi) for a unix timestamp in this clock's timezone.
     *
     * @param  int $ts Unix timestamp.
     * @return string
     */
    public function formatMinuteKeyFromTimestamp(int $ts): string;
}
