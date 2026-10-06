<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

/**
 * Fully-resolved message handed from Mailer to a MailDriverInterface
 * implementation. Built once per send() call from a MailMessage plus its
 * resolved html/text body — drivers read properties off this, nothing else.
 *
 * @package rafalmasiarek\DashboardKit\Mail\Driver
 */
final class OutboundMail
{
    /**
     * @param string                                                        $fromEmail     Sender address.
     * @param string                                                        $fromName      Sender display name.
     * @param string                                                        $toEmail       Recipient address.
     * @param string                                                        $toName        Recipient display name (may be empty).
     * @param string                                                        $subject       Email subject line.
     * @param string                                                        $htmlBody      HTML version of the message body, or the full
     *                                                                                      verbatim body when $contentType is set.
     * @param string                                                        $textBody      Plain-text version of the message body.
     * @param string|null                                                   $replyTo       Reply-To address, or null.
     * @param list<array{path: string, name?: string, mimeType?: string}>  $attachments   Files to attach.
     * @param string|null                                                   $contentType   Overrides the normal alternative/mixed
     *                                                                                      content-type construction when set — $htmlBody
     *                                                                                      is then sent verbatim as the top-level body.
     * @param string|null                                                   $encoding      Content-Transfer-Encoding paired with
     *                                                                                      $contentType; ignored when $contentType is null.
     * @param list<array{email: string, name?: string}>                     $cc            Carbon-copy recipients (header + envelope).
     * @param list<array{email: string, name?: string}>                     $bcc           Blind carbon-copy recipients (envelope only, never in headers).
     * @param list<array{path: string, cid: string, name?: string, mimeType?: string}> $embeds Inline parts referenced from the HTML body as "cid:...".
     * @param array<string, string>                                         $customHeaders Additional raw header lines, name => value.
     * @param string|null                                                   $messageId     Value for the Message-ID header; a driver generates
     *                                                                                      one when null.
     * @param string|null                                                   $inReplyTo     Message-ID this message replies to.
     * @param list<string>                                                  $references    Message-IDs for the References header.
     * @param string|null                                                   $envelopeFrom  SMTP envelope sender (MAIL FROM); defaults to
     *                                                                                      $fromEmail when null.
     * @param (\Closure(string): void)|null                                 $onDebugLine   Invoked with each raw transport transcript line, when supported by the driver.
     */
    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $toEmail,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
        public readonly ?string $contentType = null,
        public readonly ?string $encoding = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $embeds = [],
        public readonly array $customHeaders = [],
        public readonly ?string $messageId = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly ?string $envelopeFrom = null,
        public readonly ?\Closure $onDebugLine = null,
    ) {
    }
}
