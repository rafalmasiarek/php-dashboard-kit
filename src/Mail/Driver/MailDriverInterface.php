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
     * @param string $fromEmail Sender address.
     * @param string $fromName  Sender display name.
     * @param string $toEmail   Recipient address.
     * @param string $toName    Recipient display name (may be empty).
     * @param string $subject   Email subject line.
     * @param string $htmlBody  HTML version of the message body.
     * @param string $textBody  Plain-text version of the message body.
     *
     * @throws MailException When delivery fails.
     */
    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
    ): void;
}
