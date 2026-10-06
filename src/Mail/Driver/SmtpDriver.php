<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

use rafalmasiarek\DashboardKit\Mail\Exception\MailException;
use rafalmasiarek\DnsResolver\DnsResolverInterface;
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
    ): void {
        $rawBody = $contentType !== null
            ? new RawMimeBody($htmlBody, $contentType, $encoding ?? '8bit')
            : null;

        $message = MimeBuilder::build(
            $fromEmail,
            $fromName,
            $toEmail,
            $toName,
            $replyTo,
            $subject,
            $rawBody === null ? $htmlBody : null,
            $rawBody === null ? $textBody : null,
            $attachments,
            $rawBody,
            self::generateMessageId($fromEmail),
        );

        try {
            (new SmtpClient($this->dns))->send(
                (string) ($this->config['host'] ?? ''),
                (int) ($this->config['port'] ?? 587),
                (string) ($this->config['encryption'] ?? 'tls'),
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                $fromEmail,
                $toEmail,
                $message,
            );
        } catch (SmtpException $e) {
            throw new MailException('SMTP delivery failed: ' . $e->getMessage(), 0, $e);
        }
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
