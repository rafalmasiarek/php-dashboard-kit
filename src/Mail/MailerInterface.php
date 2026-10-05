<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Mail;

/**
 * Sends outgoing emails rendered from a MailMessage.
 *
 * Bound in the DI container as Mailer::class (the concrete implementation)
 * and MailerInterface::class (resolved to the same instance). Consumers should
 * type-hint the interface, not Mailer directly, so an app can substitute a
 * decorator (e.g. one that adds open-tracking) by overriding the
 * MailerInterface::class binding after Dashboard::create() — the same pattern
 * already used for RealIpResolver/GeoIpDriverInterface.
 *
 * @package rafalmasiarek\DashboardKit\Mail
 */
interface MailerInterface
{
    /**
     * Renders the message and hands it off to the configured transport driver.
     *
     * On success: emits 'mail_sent'   with (MailMessage $message).
     * On failure: emits 'mail_failed' with (MailMessage $message, \Throwable $e), then re-throws.
     *
     * @param MailMessage $message Message to send.
     *
     * @throws Exception\MailException   When the driver reports a delivery failure.
     * @throws \InvalidArgumentException When neither template nor html body is set.
     */
    public function send(MailMessage $message): void;
}
