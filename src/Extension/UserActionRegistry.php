<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Extension;

/**
 * Registry for plugin-provided user action buttons in the admin users list.
 *
 * Plugins call register() during their addon registration. The admin users
 * list template iterates all() and renders an additional button per user row,
 * constructing the URL from path_pattern by replacing {id} with the user ID.
 *
 * Action definition keys:
 *   label        (string) Button label, e.g. 'API Scopes'.
 *   path_pattern (string) URL pattern with {id} placeholder, e.g. '/admin/api/users/{id}/scopes'.
 *   order        (int)    Sort order for button positioning. Default: 100.
 *
 * @package rafalmasiarek\DashboardKit\Extension
 */
final class UserActionRegistry
{
    /** @var array<string, array{slug: string, label: string, path_pattern: string, order: int}> */
    private array $actions = [];

    /**
     * Register a user action.
     *
     * @param string               $slug       Unique identifier for this action.
     * @param array<string, mixed> $definition Action definition (label, path_pattern, order).
     * @return void
     */
    public function register(string $slug, array $definition): void
    {
        $this->actions[$slug] = [
            'slug'         => $slug,
            'label'        => (string) ($definition['label']        ?? $slug),
            'path_pattern' => (string) ($definition['path_pattern'] ?? '/admin/' . $slug . '/{id}'),
            'order'        => (int)    ($definition['order']        ?? 100),
        ];
    }

    /**
     * Return all registered actions sorted by order.
     *
     * @return array<string, array{slug: string, label: string, path_pattern: string, order: int}>
     */
    public function all(): array
    {
        $actions = $this->actions;
        \uasort($actions, static fn(array $a, array $b) => $a['order'] <=> $b['order']);
        return $actions;
    }

    /**
     * Resolve a URL for a specific user ID by replacing the {id} placeholder.
     *
     * @param string $slug   Action slug.
     * @param string $userId User ID to substitute.
     * @return string|null   Resolved URL, or null if the action is not registered.
     */
    public function urlFor(string $slug, string $userId): ?string
    {
        if (!isset($this->actions[$slug])) {
            return null;
        }
        return \str_replace('{id}', $userId, $this->actions[$slug]['path_pattern']);
    }
}
