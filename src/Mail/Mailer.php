<?php

namespace rafalmasiarek\DashboardKit\Mail;

use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use rafalmasiarek\DashboardKit\Mail\Driver\MailDriverInterface;
use rafalmasiarek\DashboardKit\Mail\Driver\OutboundMail;
use rafalmasiarek\DashboardKit\Mail\Exception\MailException;
use Slim\Views\Twig;

/**
 * Sends outgoing emails by rendering Twig templates and delegating to a transport driver.
 *
 * Registered unconditionally in the DI container as Mailer::class.
 * The active driver is determined by config['mailer']['driver']:
 *   'smtp'  — SmtpDriver (rafalmasiarek/mailer, no PHPMailer dependency)
 *   'null'  — NullDriver (logs and discards; default when unset)
 *
 * Adding a new driver: implement MailDriverInterface and add a case to the match
 * expression in Dashboard::buildContainer().
 *
 * Hooks emitted:
 *   mail_sent   (MailMessage $message)               — after successful delivery
 *   mail_failed (MailMessage $message, \Throwable $e) — on delivery failure (exception is re-thrown)
 *
 * @package rafalmasiarek\DashboardKit\Mail
 */
class Mailer implements MailerInterface
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
        $rawBody = $message->getRawBody();

        if ($rawBody !== null) {
            $html        = $rawBody->body;
            $text        = '';
            $contentType = $rawBody->contentType;
            $encoding    = $rawBody->encoding;
        } else {
            [$html, $text] = $this->resolveBody($message);
            $contentType = null;
            $encoding    = null;
        }

        $mail = new OutboundMail(
            fromEmail: $this->fromEmail,
            fromName: $this->fromName,
            toEmail: $message->getToEmail(),
            toName: $message->getToName(),
            subject: $message->getSubject(),
            htmlBody: $html,
            textBody: $text,
            replyTo: $message->getReplyTo(),
            attachments: $message->getAttachments(),
            contentType: $contentType,
            encoding: $encoding,
            cc: $message->getCc(),
            bcc: $message->getBcc(),
            embeds: $message->getEmbeds(),
            customHeaders: $message->getCustomHeaders(),
            messageId: $message->getMessageId(),
            inReplyTo: $message->getInReplyTo(),
            references: $message->getReferences(),
            envelopeFrom: $message->getEnvelopeFrom(),
            onDebugLine: $message->getDebugLineCallback(),
        );

        try {
            $this->driver->send($mail);
        } catch (\Throwable $e) {
            $this->audit->mailAttempt(false, $message->getToEmail(), $message->getSubject(), $e->getMessage(), $message->getCorrelationId());
            $this->hooks->emit('mail_failed', $message, $e);
            throw $e instanceof MailException ? $e : new MailException($e->getMessage(), 0, $e);
        }

        $this->audit->mailAttempt(true, $message->getToEmail(), $message->getSubject(), null, $message->getCorrelationId());
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

        // template() renders extra_body_html through its own Twig loop
        // above; this branch has no templating engine, so appendBodyHtml()
        // fragments are inserted directly instead — otherwise they would
        // silently never apply to a message built via html().
        $extraHtml = (array) ($message->getVariables()['extra_body_html'] ?? []);
        if ($extraHtml !== []) {
            $html = self::insertBeforeClosingTag($html, implode('', array_map('strval', $extraHtml)));
        }

        $text = $message->getTextBody() ?? self::htmlToText($html);
        return [$html, $text];
    }

    /**
     * Inserts $fragment before </body> when present, else before </html>, else
     * appends it verbatim. $html may be a bare content fragment (no document
     * wrapper at all, e.g. the built-in email templates) or a complete
     * document (e.g. an app-built HTML shell) — a blind append would land
     * $fragment after </html> in the latter case, which is invalid HTML.
     *
     * @param string $html
     * @param string $fragment
     *
     * @return string
     */
    private static function insertBeforeClosingTag(string $html, string $fragment): string
    {
        foreach (['</body>', '</html>'] as $closingTag) {
            $pos = stripos($html, $closingTag);
            if ($pos !== false) {
                return substr($html, 0, $pos) . $fragment . substr($html, $pos);
            }
        }

        return $html . $fragment;
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
