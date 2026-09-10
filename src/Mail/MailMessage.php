<?php

namespace rafalmasiarek\DashboardKit\Mail;

/**
 * Fluent builder for a single outgoing email message.
 *
 * Usage:
 *   MailMessage::to('user@example.com', 'Jane')
 *       ->subject('Activate your account')
 *       ->template('emails/activation.twig', ['link' => $url]);
 *
 * Either template() or html() must be set before passing to Mailer::send().
 *
 * @package rafalmasiarek\DashboardKit\Mail
 */
class MailMessage
{
    /** @var string Recipient display name. */
    private string $toName = '';

    /** @var string Email subject line. */
    private string $subject = '';

    /** @var string|null Twig template path relative to configured template directories. */
    private ?string $template = null;

    /** @var array<string, mixed> Variables passed to the Twig template. */
    private array $variables = [];

    /** @var string|null Explicit HTML body (used when template is not set). */
    private ?string $htmlBody = null;

    /** @var string|null Explicit plain-text body (auto-generated from HTML when null). */
    private ?string $textBody = null;

    /**
     * @param string $toEmail Recipient address.
     */
    private function __construct(private readonly string $toEmail)
    {
    }

    /**
     * Creates a new message addressed to the given recipient.
     *
     * @param string $email Recipient email address.
     * @param string $name  Recipient display name (optional).
     *
     * @return self
     */
    public static function to(string $email, string $name = ''): self
    {
        $msg         = new self($email);
        $msg->toName = $name;
        return $msg;
    }

    /**
     * Sets the email subject line.
     *
     * @param string $subject
     *
     * @return self
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Sets the Twig template to render as the message body.
     *
     * The template path is resolved via the configured Twig loaders —
     * application templates take precedence over built-in ones.
     *
     * @param string               $path      Template path (e.g. 'emails/activation.twig').
     * @param array<string, mixed> $variables Variables passed to the template.
     *
     * @return self
     */
    public function template(string $path, array $variables = []): self
    {
        $this->template  = $path;
        $this->variables = $variables;
        return $this;
    }

    /**
     * Sets an explicit HTML body, bypassing Twig rendering.
     *
     * @param string $html
     *
     * @return self
     */
    public function html(string $html): self
    {
        $this->htmlBody = $html;
        return $this;
    }

    /**
     * Sets an explicit plain-text body.
     *
     * When not set, Mailer auto-generates one by stripping tags from the HTML body.
     *
     * @param string $text
     *
     * @return self
     */
    public function text(string $text): self
    {
        $this->textBody = $text;
        return $this;
    }

    /**
     * @return string
     */
    public function getToEmail(): string
    {
        return $this->toEmail;
    }

    /**
     * @return string
     */
    public function getToName(): string
    {
        return $this->toName;
    }

    /**
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return $this->template;
    }

    /**
     * @return array<string, mixed>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * @return string|null
     */
    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    /**
     * @return string|null
     */
    public function getTextBody(): ?string
    {
        return $this->textBody;
    }
}
