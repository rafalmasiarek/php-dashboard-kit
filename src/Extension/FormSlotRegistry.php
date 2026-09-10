<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Extension;

/**
 * Registry for plugin-provided HTML snippets injected into auth forms.
 *
 * Plugins call register() during their addon registration. Each slot is
 * identified by a form name ('login', 'register') and a slot name ('form_fields',
 * 'scripts'). Slots are rendered via the form_slot() Twig function.
 *
 * Content can be a plain HTML string or a callable that returns a string.
 * Callables are evaluated at render time, giving access to PHP runtime state
 * such as $_SESSION, which is useful for conditional injection (e.g. show
 * reCAPTCHA only after a threshold of failed login attempts).
 *
 * Built-in slot names used by dashboard-kit templates:
 *   form_fields — injected inside the <form> element, before the submit button.
 *   scripts     — injected in the page's scripts block, outside the <form>.
 *
 * @package rafalmasiarek\DashboardKit\Extension
 */
final class FormSlotRegistry
{
    /**
     * Registered slot entries keyed by "{form}.{slot}".
     *
     * @var array<string, list<array{content: string|callable(): string, order: int}>>
     */
    private array $slots = [];

    /**
     * Register an HTML snippet or a callable for a specific form slot.
     *
     * @param string          $form    Form name, e.g. 'login' or 'register'.
     * @param string          $slot    Slot name, e.g. 'form_fields' or 'scripts'.
     * @param string|callable $content HTML string or callable returning an HTML string.
     *                                 Callable signature: (): string
     * @param int             $order   Sort order. Lower numbers are rendered first.
     * @return void
     */
    public function register(string $form, string $slot, string|callable $content, int $order = 100): void
    {
        $this->slots[$form . '.' . $slot][] = ['content' => $content, 'order' => $order];
    }

    /**
     * Render all registered snippets for the given form and slot.
     *
     * Entries are sorted by order before concatenation. Callable entries are
     * invoked at render time so they can inspect session or request state.
     *
     * @param string $form Form name, e.g. 'login'.
     * @param string $slot Slot name, e.g. 'form_fields'.
     * @return string       Concatenated HTML, safe to output raw.
     */
    public function render(string $form, string $slot): string
    {
        $key     = $form . '.' . $slot;
        $entries = $this->slots[$key] ?? [];

        if ($entries === []) {
            return '';
        }

        \usort($entries, static fn(array $a, array $b) => $a['order'] <=> $b['order']);

        $parts = [];
        foreach ($entries as $entry) {
            $parts[] = \is_callable($entry['content'])
                ? ($entry['content'])()
                : $entry['content'];
        }

        return \implode("\n", $parts);
    }
}
