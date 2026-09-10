<?php

namespace rafalmasiarek\DashboardKit\Twig;

use rafalmasiarek\Csrf\Csrf;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that exposes the csrf_field() function for use in templates.
 *
 * Usage in any form:
 *   {{ csrf_field('login') }}
 *
 * Renders two hidden inputs:
 *   <input type="hidden" name="_csrf_container" value="login">
 *   <input type="hidden" name="_csrf" value="GENERATED_TOKEN">
 *
 * The container name isolates tokens per form — a token generated for 'login'
 * cannot be replayed on 'register' or any module form.
 *
 * @package rafalmasiarek\DashboardKit\Twig
 */
class CsrfExtension extends AbstractExtension
{
    /**
     * @param Csrf $csrf CSRF token service.
     */
    public function __construct(private readonly Csrf $csrf)
    {
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
     * Generates both hidden fields for a named CSRF container.
     *
     * @param  string $container Unique form identifier (e.g. 'login', 'register', module slug).
     * @return string HTML-safe hidden input markup.
     */
    public function renderField(string $container): string
    {
        $token = $this->csrf->generateFor($container);

        return sprintf(
            '<input type="hidden" name="_csrf_container" value="%s">' .
            '<input type="hidden" name="_csrf" value="%s">',
            htmlspecialchars($container, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
