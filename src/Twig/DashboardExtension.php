<?php

namespace rafalmasiarek\DashboardKit\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * General-purpose Twig functions for the dashboard layout.
 *
 * @package rafalmasiarek\DashboardKit\Twig
 */
class DashboardExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('gravatar_url', $this->gravatarUrl(...)),
        ];
    }

    /**
     * Returns the Gravatar URL for the given email address.
     *
     * Falls back to identicon when no Gravatar is registered.
     *
     * @param  string $email User email address.
     * @param  int    $size  Avatar size in pixels (1–2048). Default: 40.
     * @return string HTTPS URL to the avatar image.
     */
    public function gravatarUrl(string $email, int $size = 40): string
    {
        $hash = md5(strtolower(trim($email)));
        return sprintf('https://www.gravatar.com/avatar/%s?s=%d&d=identicon', $hash, $size);
    }
}
