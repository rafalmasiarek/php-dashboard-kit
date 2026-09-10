<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Extension;

/**
 * Registry for plugin-provided settings sections.
 *
 * Plugins call register() during their addon registration. The settings
 * template iterates all() to render a navigation list at the bottom of
 * the built-in settings page, linking each section to its own URL.
 *
 * Section definition keys:
 *   title  (string) Display name shown in the settings navigation.
 *   path   (string) Absolute URL path, e.g. '/settings/api-tokens'.
 *   order  (int)    Sort order. Lower numbers appear first. Default: 100.
 *
 * @package rafalmasiarek\DashboardKit\Extension
 */
final class SettingsSectionRegistry
{
    /** @var array<string, array{title: string, path: string, order: int}> */
    private array $sections = [];

    /**
     * Register a settings section.
     *
     * @param string               $slug       Unique identifier for this section.
     * @param array<string, mixed> $definition Section definition (title, path, order).
     * @return void
     */
    public function register(string $slug, array $definition): void
    {
        $this->sections[$slug] = [
            'slug'  => $slug,
            'title' => (string) ($definition['title'] ?? $slug),
            'path'  => (string) ($definition['path']  ?? '/settings/' . $slug),
            'order' => (int)    ($definition['order'] ?? 100),
        ];
    }

    /**
     * Return all registered sections sorted by order.
     *
     * @return array<string, array{slug: string, title: string, path: string, order: int}>
     */
    public function all(): array
    {
        $sections = $this->sections;
        \uasort($sections, static fn(array $a, array $b) => $a['order'] <=> $b['order']);
        return $sections;
    }
}
