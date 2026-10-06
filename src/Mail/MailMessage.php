<?php

namespace rafalmasiarek\DashboardKit\Mail;

use rafalmasiarek\Mailer\RawMimeBody;

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

    /**
     * Raw HTML fragments queued via appendBodyHtml(), flattened into the
     * 'extra_body_html' template variable (sorted by priority) on read.
     *
     * @var list<array{html: string, priority: int}>
     */
    private array $bodyHtmlAppends = [];

    /** @var string|null Explicit HTML body (used when template is not set). */
    private ?string $htmlBody = null;

    /** @var string|null Explicit plain-text body (auto-generated from HTML when null). */
    private ?string $textBody = null;

    /** @var string|null Reply-To address. */
    private ?string $replyTo = null;

    /** @var list<array{path: string, name?: string}> */
    private array $attachments = [];

    /** @var RawMimeBody|null */
    private ?RawMimeBody $rawBody = null;

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
     * Merges one additional template variable in, without disturbing any
     * others already set — the only way to add a variable after template()
     * has already replaced the full set. Lets a Mailer decorator inject its
     * own value (e.g. appending to the 'extra_body_html' list layout.twig
     * renders) into whatever message it's wrapping, without needing to know
     * or preserve the caller's other variables.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    public function variable(string $key, mixed $value): self
    {
        $this->variables[$key] = $value;
        return $this;
    }

    /**
     * Queues a raw HTML fragment to render after the main content, via the
     * 'extra_body_html' loop in layout.twig — the generic extension point
     * for a Mailer decorator to append something to every email (e.g. an
     * open-tracking pixel) without layout.twig knowing what it is.
     *
     * Fragments render in ascending $priority order (ties keep insertion
     * order) — a tracking pixel should use a high priority (e.g. 999) to
     * guarantee it renders after anything else appended.
     *
     * @param string $html
     * @param int    $priority Lower renders first; default 0.
     *
     * @return self
     */
    public function appendBodyHtml(string $html, int $priority = 0): self
    {
        $this->bodyHtmlAppends[] = ['html' => $html, 'priority' => $priority];
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
     * Sets the Reply-To address.
     *
     * @param string $email
     *
     * @return self
     */
    public function replyTo(string $email): self
    {
        $this->replyTo = $email;
        return $this;
    }

    /**
     * Queues a file attachment.
     *
     * @param string $path Absolute path to the file.
     * @param string $name Attachment filename; defaults to the file's own basename.
     *
     * @return self
     */
    public function attach(string $path, string $name = ''): self
    {
        $this->attachments[] = $name !== '' ? ['path' => $path, 'name' => $name] : ['path' => $path];
        return $this;
    }

    /**
     * Sets a verbatim MIME body, bypassing template()/html()/text() resolution
     * entirely. Used for content types the normal alternative/mixed structure
     * cannot represent.
     *
     * @param RawMimeBody $body
     *
     * @return self
     */
    public function rawBody(RawMimeBody $body): self
    {
        $this->rawBody = $body;
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
     * Returns the template variables, with any appendBodyHtml() fragments
     * flattened into 'extra_body_html' (sorted by priority ascending) —
     * overwrites a same-named key set via template()'s own $variables.
     *
     * @return array<string, mixed>
     */
    public function getVariables(): array
    {
        if ($this->bodyHtmlAppends === []) {
            return $this->variables;
        }

        $sorted = $this->bodyHtmlAppends;
        usort($sorted, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return [...$this->variables, 'extra_body_html' => array_column($sorted, 'html')];
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

    /**
     * @return string|null
     */
    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    /**
     * @return list<array{path: string, name?: string}>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return RawMimeBody|null
     */
    public function getRawBody(): ?RawMimeBody
    {
        return $this->rawBody;
    }
}
