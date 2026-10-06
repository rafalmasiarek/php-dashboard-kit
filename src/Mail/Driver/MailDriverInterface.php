<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

use rafalmasiarek\DashboardKit\Mail\Exception\MailException;

/**
 * Contract for outgoing mail transport drivers.
 *
 * Implement this interface to add a new driver (Mailgun, SES, Postmark, …).
 * Register it by returning the new class from the 'driver' match in Dashboard::buildContainer().
 *
 * @package rafalmasiarek\DashboardKit\Mail\Driver
 */
interface MailDriverInterface
{
    /**
     * Delivers a single email message.
     *
     * @param OutboundMail $mail Fully-resolved message to deliver.
     *
     * @throws MailException When delivery fails.
     */
    public function send(OutboundMail $mail): void;
}
