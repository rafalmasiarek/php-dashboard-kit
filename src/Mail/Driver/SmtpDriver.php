<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

use rafalmasiarek\DashboardKit\Mail\Exception\MailException;
use rafalmasiarek\DnsResolver\DnsResolverInterface;
use rafalmasiarek\Mailer\DkimSigner;
use rafalmasiarek\Mailer\MimeBuilder;
use rafalmasiarek\Mailer\RawMimeBody;
use rafalmasiarek\Mailer\SmtpClient;
use rafalmasiarek\Mailer\SmtpException;

/**
 * Delivers mail via SMTP using rafalmasiarek/mailer's from-scratch SmtpClient.
 *
 * Config keys (passed as $config array):
 *   host       — SMTP server hostname
 *   port       — SMTP port (default: 587)
 *   username   — SMTP username (leave empty to disable auth)
 *   password   — SMTP password
 *   encryption — 'tls' (STARTTLS), 'ssl' (implicit TLS), or '' (none)
 *   dkim       — optional ['private_key' => PEM string, 'domain' => ..., 'selector' => ...,
 *                 'headers' => list<string> (default: From, To, Subject, Date, Message-ID)]
 *                 Signing is skipped entirely when 'private_key' is empty/absent.
 *
 * @package rafalmasiarek\DashboardKit\Mail\Driver
 */
class SmtpDriver implements MailDriverInterface
{
    /**
     * @param array<string, mixed> $config SMTP connection parameters.
     * @param DnsResolverInterface $dns    Resolver used to pin the connection's target IP.
     */
    public function __construct(
        private readonly array $config,
        private readonly DnsResolverInterface $dns,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(OutboundMail $mail): void
    {
        $rawBody = $mail->contentType !== null
            ? new RawMimeBody($mail->htmlBody, $mail->contentType, $mail->encoding ?? '8bit')
            : null;

        $message = MimeBuilder::build(
            fromEmail: $mail->fromEmail,
            fromName: $mail->fromName,
            toEmail: $mail->toEmail,
            toName: $mail->toName,
            replyTo: $mail->replyTo,
            subject: $mail->subject,
            htmlBody: $rawBody === null ? $mail->htmlBody : null,
            textBody: $rawBody === null ? $mail->textBody : null,
            attachments: $mail->attachments,
            rawBody: $rawBody,
            messageId: $mail->messageId ?? self::generateMessageId($mail->fromEmail),
            cc: $mail->cc,
            embeds: $mail->embeds,
            customHeaders: $mail->customHeaders,
            inReplyTo: $mail->inReplyTo,
            references: $mail->references,
        );

        $message = $this->maybeSign($message);

        $envelopeRecipients = [$mail->toEmail];
        foreach ($mail->cc as $addr) {
            $envelopeRecipients[] = $addr['email'];
        }
        foreach ($mail->bcc as $addr) {
            $envelopeRecipients[] = $addr['email'];
        }

        $client = new SmtpClient($this->dns);
        if ($mail->onDebugLine !== null) {
            $client->setDebugCallback($mail->onDebugLine);
        }

        try {
            $client->send(
                (string) ($this->config['host'] ?? ''),
                (int) ($this->config['port'] ?? 587),
                (string) ($this->config['encryption'] ?? 'tls'),
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                $mail->envelopeFrom ?? $mail->fromEmail,
                $envelopeRecipients,
                $message,
            );
        } catch (SmtpException $e) {
            throw new MailException('SMTP delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Signs the message with DKIM when config['dkim']['private_key'] is set; returns it
     * unchanged otherwise.
     *
     * @param string $rawMessage
     *
     * @return string
     */
    private function maybeSign(string $rawMessage): string
    {
        $dkim       = (array) ($this->config['dkim'] ?? []);
        $privateKey = (string) ($dkim['private_key'] ?? '');
        if ($privateKey === '') {
            return $rawMessage;
        }

        $headers = isset($dkim['headers'])
            ? (array) $dkim['headers']
            : ['From', 'To', 'Subject', 'Date', 'Message-ID'];

        return DkimSigner::sign(
            $rawMessage,
            $privateKey,
            (string) ($dkim['domain'] ?? ''),
            (string) ($dkim['selector'] ?? ''),
            $headers,
        );
    }

    /**
     * Generates a Message-ID local part + domain from the sender address.
     *
     * @param string $fromEmail
     *
     * @return string
     */
    private static function generateMessageId(string $fromEmail): string
    {
        $domain = \substr(\strrchr($fromEmail, '@') ?: '@localhost', 1);
        return \bin2hex(\random_bytes(16)) . '@' . $domain;
    }
}
