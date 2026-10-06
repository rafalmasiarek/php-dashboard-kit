<?php

namespace rafalmasiarek\DashboardKit\Mail\Driver;

/**
 * Development driver that suppresses all outgoing mail silently.
 *
 * Use driver: 'null' in the mailer config to prevent real emails during
 * local development or testing. Delivery outcomes are recorded by the Mailer
 * via AuditLog — no additional logging is done here.
 *
 * @package rafalmasiarek\DashboardKit\Mail\Driver
 */
class NullDriver implements MailDriverInterface
{
    /**
     * {@inheritdoc}
     */
    public function send(OutboundMail $mail): void
    {
        // suppressed
    }
}
