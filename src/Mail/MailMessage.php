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

    /** @var list<array{path: string, name?: string, mimeType?: string}> */
    private array $attachments = [];

    /** @var RawMimeBody|null */
    private ?RawMimeBody $rawBody = null;

    /** @var list<array{email: string, name?: string}> */
    private array $cc = [];

    /** @var list<array{email: string, name?: string}> */
    private array $bcc = [];

    /** @var list<array{path: string, cid: string, name?: string, mimeType?: string}> */
    private array $embeds = [];

    /** @var array<string, string> */
    private array $customHeaders = [];

    /** @var string|null Caller-assigned Message-ID; a driver generates one when null. */
    private ?string $messageId = null;

    /** @var string|null Message-ID this message replies to. */
    private ?string $inReplyTo = null;

    /** @var list<string> */
    private array $references = [];

    /** @var string|null SMTP envelope sender (MAIL FROM); defaults to the Mailer's fromEmail when null. */
    private ?string $envelopeFrom = null;

    /** @var \Closure(string): void|null */
    private ?\Closure $onDebugLine = null;

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
     * @param string      $path     Absolute path to the file.
     * @param string      $name     Attachment filename; defaults to the file's own basename.
     * @param string|null $mimeType Explicit Content-Type; guessed from the file when null.
     *
     * @return self
     */
    public function attach(string $path, string $name = '', ?string $mimeType = null): self
    {
        $attachment = ['path' => $path];
        if ($name !== '') {
            $attachment['name'] = $name;
        }
        if ($mimeType !== null) {
            $attachment['mimeType'] = $mimeType;
        }
        $this->attachments[] = $attachment;
        return $this;
    }

    /**
     * Adds a carbon-copy recipient. Call multiple times for multiple recipients.
     *
     * @param string $email
     * @param string $name
     *
     * @return self
     */
    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = $name !== '' ? ['email' => $email, 'name' => $name] : ['email' => $email];
        return $this;
    }

    /**
     * Adds a blind carbon-copy recipient — envelope-only, never appears in any header.
     * Call multiple times for multiple recipients.
     *
     * @param string $email
     * @param string $name
     *
     * @return self
     */
    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = $name !== '' ? ['email' => $email, 'name' => $name] : ['email' => $email];
        return $this;
    }

    /**
     * Queues an inline part referenced from the HTML body as "cid:$cid".
     * Call multiple times for multiple embeds.
     *
     * @param string      $path     Absolute path to the file.
     * @param string      $cid      Content-ID to reference as "cid:$cid" in the HTML body.
     * @param string      $name     Attachment filename; defaults to the file's own basename.
     * @param string|null $mimeType Explicit Content-Type; guessed from the file when null.
     *
     * @return self
     */
    public function embed(string $path, string $cid, string $name = '', ?string $mimeType = null): self
    {
        $embed = ['path' => $path, 'cid' => $cid];
        if ($name !== '') {
            $embed['name'] = $name;
        }
        if ($mimeType !== null) {
            $embed['mimeType'] = $mimeType;
        }
        $this->embeds[] = $embed;
        return $this;
    }

    /**
     * Sets an additional raw header. Call multiple times for multiple headers;
     * a repeated $name overwrites the earlier value.
     *
     * @param string $name
     * @param string $value
     *
     * @return self
     */
    public function customHeader(string $name, string $value): self
    {
        $this->customHeaders[$name] = $value;
        return $this;
    }

    /**
     * Sets the Message-ID header value. A driver generates one when unset.
     *
     * @param string $id Without angle brackets.
     *
     * @return self
     */
    public function messageId(string $id): self
    {
        $this->messageId = $id;
        return $this;
    }

    /**
     * Sets the In-Reply-To header, threading this message to a prior one.
     *
     * @param string $messageId Without angle brackets.
     *
     * @return self
     */
    public function inReplyTo(string $messageId): self
    {
        $this->inReplyTo = $messageId;
        return $this;
    }

    /**
     * Sets the References header.
     *
     * @param list<string> $messageIds Without angle brackets.
     *
     * @return self
     */
    public function references(array $messageIds): self
    {
        $this->references = $messageIds;
        return $this;
    }

    /**
     * Sets the SMTP envelope sender (MAIL FROM), independent of the visible From: header.
     * Useful for routing bounces to a dedicated mailbox.
     *
     * @param string $email
     *
     * @return self
     */
    public function envelopeFrom(string $email): self
    {
        $this->envelopeFrom = $email;
        return $this;
    }

    /**
     * Registers a callback invoked with each raw transport transcript line,
     * when supported by the active driver.
     *
     * @param callable(string): void $callback
     *
     * @return self
     */
    public function onDebugLine(callable $callback): self
    {
        $this->onDebugLine = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
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
     * @return list<array{path: string, name?: string, mimeType?: string}>
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

    /**
     * @return list<array{email: string, name?: string}>
     */
    public function getCc(): array
    {
        return $this->cc;
    }

    /**
     * @return list<array{email: string, name?: string}>
     */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    /**
     * @return list<array{path: string, cid: string, name?: string, mimeType?: string}>
     */
    public function getEmbeds(): array
    {
        return $this->embeds;
    }

    /**
     * @return array<string, string>
     */
    public function getCustomHeaders(): array
    {
        return $this->customHeaders;
    }

    /**
     * @return string|null
     */
    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    /**
     * @return string|null
     */
    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    /**
     * @return list<string>
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * @return string|null
     */
    public function getEnvelopeFrom(): ?string
    {
        return $this->envelopeFrom;
    }

    /**
     * @return \Closure(string): void|null
     */
    public function getDebugLineCallback(): ?\Closure
    {
        return $this->onDebugLine;
    }
}
