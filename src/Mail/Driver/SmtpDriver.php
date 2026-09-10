<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

use rafalmasiarek\DashboardKit\Mail\Exception\MailException;

/**
 * Delivers mail via SMTP using PHPMailer.
 *
 * Requires: composer require phpmailer/phpmailer
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
     */
    public function __construct(private readonly array $config)
    {
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
    ): void {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new MailException(
                'phpmailer/phpmailer is required for the SMTP driver. Run: composer require phpmailer/phpmailer'
            );
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host     = (string) ($this->config['host'] ?? '');
            $mail->Port     = (int)    ($this->config['port'] ?? 587);
            $mail->SMTPAuth = !empty($this->config['username']);

            if ($mail->SMTPAuth) {
                $mail->Username = (string) ($this->config['username'] ?? '');
                $mail->Password = (string) ($this->config['password'] ?? '');
            }

            $mail->SMTPSecure = match ($this->config['encryption'] ?? 'tls') {
                'ssl'   => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
                'tls'   => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };

            $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            throw new MailException('SMTP delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
