<?php

namespace rafalmasiarek\DashboardKit\Mail;

use AuthKit\Extension\SchemaProviderInterface;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use rafalmasiarek\DashboardKit\Mail\Driver\MailDriverInterface;
use rafalmasiarek\DashboardKit\Mail\Exception\MailException;
use Slim\Views\Twig;

/**
 * Sends outgoing emails by rendering Twig templates and delegating to a transport driver.
 *
 * Registered in the DI container as Mailer::class when 'mailer' config key is present.
 * The active driver is determined by config['mailer']['driver']:
 *   'smtp'  — SmtpDriver (requires phpmailer/phpmailer)
 *   'null'  — NullDriver (logs and discards; default for dev)
 *
 * Adding a new driver: implement MailDriverInterface and add a case to the match
 * expression in Dashboard::buildContainer().
 *
 * Hooks emitted:
 *   mail_sent   (MailMessage $message)               — after successful delivery
 *   mail_failed (MailMessage $message, \Throwable $e) — on delivery failure (exception is re-thrown)
 *
 * Implements SchemaProviderInterface to declare the mail_tracking table, which
 * records open-pixel events for every outgoing email from the dashboard.
 *
 * @package rafalmasiarek\DashboardKit\Mail
 */
class Mailer implements SchemaProviderInterface
{
    /**
     * @param MailDriverInterface $driver    Active transport driver.
     * @param Twig                $view      Twig engine used to render email templates.
     * @param string              $fromEmail Default sender address.
     * @param string              $fromName  Default sender display name.
     * @param HookRegistry        $hooks     Hook registry — fires mail_sent / mail_failed events.
     * @param AuditLog            $audit     Audit logger — records structured delivery outcomes.
     */
    public function __construct(
        private readonly MailDriverInterface $driver,
        private readonly Twig               $view,
        private readonly string             $fromEmail,
        private readonly string             $fromName,
        private readonly HookRegistry       $hooks,
        private readonly AuditLog           $audit,
    ) {
    }

    /**
     * Renders the message, hands it off to the configured driver, and emits a result hook.
     *
     * On success: emits 'mail_sent'   with (MailMessage $message).
     * On failure: emits 'mail_failed' with (MailMessage $message, \Throwable $e), then re-throws.
     *
     * @param MailMessage $message Message to send.
     *
     * @throws MailException         When the driver reports a delivery failure.
     * @throws \InvalidArgumentException When neither template nor html body is set.
     */
    public function send(MailMessage $message): void
    {
        [$html, $text] = $this->resolveBody($message);

        try {
            $this->driver->send(
                $this->fromEmail,
                $this->fromName,
                $message->getToEmail(),
                $message->getToName(),
                $message->getSubject(),
                $html,
                $text,
            );
        } catch (\Throwable $e) {
            $this->audit->mailAttempt(false, $message->getToEmail(), $message->getSubject(), $e->getMessage());
            $this->hooks->emit('mail_failed', $message, $e);
            throw $e instanceof MailException ? $e : new MailException($e->getMessage(), 0, $e);
        }

        $this->audit->mailAttempt(true, $message->getToEmail(), $message->getSubject());
        $this->hooks->emit('mail_sent', $message);
    }

    /**
     * Resolves the final HTML and plain-text bodies from the message.
     *
     * When a Twig template is set it takes precedence over an explicit html() body.
     * The plain-text body is auto-generated from the rendered HTML when not explicitly set.
     *
     * @param  MailMessage $message
     * @return array{0: string, 1: string} [htmlBody, textBody]
     *
     * @throws \InvalidArgumentException When neither template nor html body is provided.
     */
    private function resolveBody(MailMessage $message): array
    {
        if ($message->getTemplate() !== null) {
            $html = $this->view->fetch($message->getTemplate(), $message->getVariables());
            $text = $message->getTextBody() ?? self::htmlToText($html);
            return [$html, $text];
        }

        $html = $message->getHtmlBody();
        if ($html === null) {
            throw new \InvalidArgumentException(
                'MailMessage requires either template() or html() to be set before sending.'
            );
        }

        $text = $message->getTextBody() ?? self::htmlToText($html);
        return [$html, $text];
    }

    /**
     * Declares the mail_tracking table used for open-pixel audit tracking.
     *
     * Every email sent by Mailer::send() can embed a tracking pixel whose token
     * is stored here. When the recipient opens the email the pixel fires, and
     * the opened_at timestamp and IP are recorded via the /mail/track/:token route.
     *
     * @param  string        $driver PDO driver name.
     * @return list<string>
     */
    public function additionalSchema(string $driver): array
    {
        if ($driver === 'sqlite') {
            return [
                'CREATE TABLE IF NOT EXISTS mail_tracking (
                    id         INTEGER  NOT NULL PRIMARY KEY AUTOINCREMENT,
                    token      TEXT     NOT NULL UNIQUE,
                    to_email   TEXT     NOT NULL,
                    mail_type  TEXT     NOT NULL,
                    sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    opened_at  DATETIME NULL DEFAULT NULL,
                    open_ip    TEXT     NULL DEFAULT NULL
                )',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS mail_tracking (
                id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token      CHAR(32)     NOT NULL UNIQUE,
                to_email   VARCHAR(255) NOT NULL,
                mail_type  VARCHAR(50)  NOT NULL,
                sent_at    DATETIME     NOT NULL DEFAULT NOW(),
                opened_at  DATETIME     NULL DEFAULT NULL,
                open_ip    VARCHAR(45)  NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /**
     * Strips HTML tags and normalises whitespace to produce a plain-text fallback.
     *
     * @param  string $html
     * @return string
     */
    private static function htmlToText(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/p>/i', '/<\/h[1-6]>/i'], "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
