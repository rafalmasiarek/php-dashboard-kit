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
     * @param string                                    $fromEmail   Sender address.
     * @param string                                    $fromName    Sender display name.
     * @param string                                    $toEmail     Recipient address.
     * @param string                                    $toName      Recipient display name (may be empty).
     * @param string                                    $subject     Email subject line.
     * @param string                                    $htmlBody    HTML version of the message body, or the full
     *                                                                verbatim body when $contentType is set.
     * @param string                                    $textBody    Plain-text version of the message body.
     * @param string|null                               $replyTo     Reply-To address, or null.
     * @param list<array{path: string, name?: string}>  $attachments Files to attach.
     * @param string|null                                $contentType Overrides the normal alternative/mixed
     *                                                                content-type construction when set — $htmlBody
     *                                                                is then sent verbatim as the top-level body.
     * @param string|null                                $encoding   Content-Transfer-Encoding paired with
     *                                                                $contentType; ignored when $contentType is null.
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
        ?string $replyTo = null,
        array $attachments = [],
        ?string $contentType = null,
        ?string $encoding = null,
    ): void;
}
