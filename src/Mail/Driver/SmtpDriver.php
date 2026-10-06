<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

use rafalmasiarek\DashboardKit\Mail\Exception\MailException;
use rafalmasiarek\DnsResolver\DnsResolverInterface;
use rafalmasiarek\Mailer\DeadLetterStoreInterface;
use rafalmasiarek\Mailer\DkimSigner;
use rafalmasiarek\Mailer\DsnOptions;
use rafalmasiarek\Mailer\MimeBuilder;
use rafalmasiarek\Mailer\RawMimeBody;
use rafalmasiarek\Mailer\SmtpClient;
use rafalmasiarek\Mailer\SmtpException;
use rafalmasiarek\Mailer\TlsOptions;

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
 *   tls        — optional ['verify_peer' => bool (default true), 'ca_file' => string,
 *                 'client_cert_file' => string, 'client_key_file' => string,
 *                 'client_key_passphrase' => string]
 *   dsn        — optional ['enabled' => bool (default false), 'ret' => 'HDRS'|'FULL',
 *                 'notify' => list<'SUCCESS'|'FAILURE'|'DELAY'|'NEVER'>]. Applied to every
 *                 message when enabled; silently ignored by servers that don't advertise DSN.
 *
 * @package rafalmasiarek\DashboardKit\Mail\Driver
 */
class SmtpDriver implements MailDriverInterface
{
    private readonly TlsOptions $tls;

    private readonly ?DsnOptions $dsn;

    /**
     * @param array<string, mixed>          $config          SMTP connection parameters.
     * @param DnsResolverInterface           $dns             Resolver used to pin the connection's target IP.
     * @param DeadLetterStoreInterface|null $deadLetterStore Receives a FailedDelivery on any send failure, when set.
     */
    public function __construct(
        private readonly array $config,
        private readonly DnsResolverInterface $dns,
        private readonly ?DeadLetterStoreInterface $deadLetterStore = null,
    ) {
        $tlsConfig  = (array) ($config['tls'] ?? []);
        $this->tls = new TlsOptions(
            verifyPeer: (bool) ($tlsConfig['verify_peer'] ?? true),
            caFile: isset($tlsConfig['ca_file']) ? (string) $tlsConfig['ca_file'] : null,
            clientCertFile: isset($tlsConfig['client_cert_file']) ? (string) $tlsConfig['client_cert_file'] : null,
            clientKeyFile: isset($tlsConfig['client_key_file']) ? (string) $tlsConfig['client_key_file'] : null,
            clientKeyPassphrase: isset($tlsConfig['client_key_passphrase']) ? (string) $tlsConfig['client_key_passphrase'] : null,
        );

        $dsnConfig  = (array) ($config['dsn'] ?? []);
        $this->dsn = (bool) ($dsnConfig['enabled'] ?? false)
            ? new DsnOptions(
                ret: (string) ($dsnConfig['ret'] ?? 'HDRS'),
                notify: (array) ($dsnConfig['notify'] ?? ['FAILURE', 'DELAY']),
            )
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function send(OutboundMail $mail): void
    {
        $rawBody = $mail->contentType !== null
            ? new RawMimeBody($mail->htmlBody, $mail->contentType, $mail->encoding ?? '8bit')
            : null;

        $messageId = $mail->messageId ?? self::generateMessageId($mail->fromEmail);

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
            messageId: $messageId,
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

        $dsn = $this->dsn !== null
            ? new DsnOptions($this->dsn->ret, $this->dsn->notify, $messageId)
            : null;

        $client = new SmtpClient(
            $this->dns,
            deadLetterStore: $this->deadLetterStore,
            tls: $this->tls,
        );
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
                $dsn,
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
        $at = \strrchr($fromEmail, '@');
        if ($at !== false) {
            $domain = \substr($at, 1);
        } else {
            $domain = \gethostname();
            if ($domain === false || $domain === '') {
                $domain = \php_uname('n');
            }
            if ($domain === '') {
                $domain = 'localhost';
            }
        }

        return \bin2hex(\random_bytes(16)) . '@' . $domain;
    }
}
