<?php

namespace rafalmasiarek\DashboardKit\Twig;

use rafalmasiarek\Csrf\Csrf;
use rafalmasiarek\Csrf\Helpers\HtmlHelper;
use rafalmasiarek\RealIpResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that exposes the csrf_field() function for use in templates.
 *
 * Usage in any form:
 *   {{ csrf_field('login') }}
 *
 * Renders via the package's own HtmlHelper::input(), which emits:
 *   <input type="hidden" name="_csrf" value="GENERATED_TOKEN">
 *   <input type="hidden" name="_csrf_container" value="login">
 *   <input type="hidden" name="_csrf_proof" value="PROOF">   (only when a session-bound proof is available)
 *
 * The container name isolates tokens per form — a token generated for 'login'
 * cannot be replayed on 'register' or any module form.
 *
 * @package rafalmasiarek\DashboardKit\Twig
 */
class CsrfExtension extends AbstractExtension
{
    /**
     * @param Csrf           $csrf      CSRF token service.
     * @param RealIpResolver $ipResolver Resolves the real client IP behind trusted proxies
     *                                   (e.g. Cloudflare), so tokens are issued bound to the
     *                                   same address CsrfMiddleware validates against.
     */
    public function __construct(
        private readonly Csrf $csrf,
        private readonly RealIpResolver $ipResolver,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_field', $this->renderField(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Renders the hidden CSRF inputs for a named container.
     *
     * $ip defaults to the resolved real client IP (see RealIpResolver above); $userAgent
     * defaults to Csrf's own auto-detection. Both accept an explicit override for callers
     * with a reason to bind the token to something other than the current request (e.g.
     * issuing a token server-side, outside of a normal request/response cycle).
     *
     * @param  string      $container Unique form identifier (e.g. 'login', 'register', module slug).
     * @param  string|null $ip        Optional client IP override.
     * @param  string|null $userAgent Optional User-Agent override.
     * @return string HTML-safe hidden input markup.
     */
    public function renderField(string $container, ?string $ip = null, ?string $userAgent = null): string
    {
        return HtmlHelper::input(
            $this->csrf,
            $container,
            '_csrf',
            $ip ?? ($this->ipResolver->getIp() ?: null),
            $userAgent
        );
    }
}
