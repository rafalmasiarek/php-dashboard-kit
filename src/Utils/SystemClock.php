<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Utils;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Default ClockInterface — plain system time, no timezone awareness.
 *
 * Used when nothing else is bound to ClockInterface::class. An app with its
 * own timezone-aware clock should override this binding after
 * Dashboard::create() — the same pattern already used for MailerInterface,
 * RealIpResolver, and GeoIpDriverInterface.
 *
 * @package rafalmasiarek\DashboardKit\Utils
 */
final class SystemClock implements ClockInterface
{
    /**
     * {@inheritdoc}
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
