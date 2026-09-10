<?php

namespace rafalmasiarek\DashboardKit\Module;

/**
 * Discovers and indexes application modules from a directory tree.
 *
 * Each module is a directory containing a module.php file that returns
 * a configuration array. The registry tracks template paths and provides
 * filtered views for navbar rendering and scheduling.
 *
 * @package rafalmasiarek\DashboardKit\Module
 */
class ModuleRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $modules = [];

    /**
     * Scans a directory for module.php files and registers each module found.
     *
     * Calling discover() multiple times merges results — later calls override
     * earlier ones when slugs collide, allowing user modules to shadow built-ins.
     *
     * @param string $dir Root directory to scan (glob pattern: {dir}/[*]/module.php).
     */
    public function discover(string $dir): void
    {
        foreach (glob($dir . '/*/module.php') ?: [] as $file) {
            $module = require $file;
            if (!is_array($module) || empty($module['slug'])) {
                continue;
            }

            $templatesDir = dirname($file) . '/templates';
            if (is_dir($templatesDir)) {
                $module['_templates_dir'] = $templatesDir;
            }

            $module['path'] = self::resolvePath($module);
            $this->modules[$module['slug']] = $module;
        }

        uasort($this->modules, static fn($a, $b) =>
            ($a['order'] ?? 99) <=> ($b['order'] ?? 99)
        );
    }

    /**
     * Returns all registered modules sorted by order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Finds a single module by its slug.
     *
     * @param  string $slug Module slug identifier.
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->modules[$slug] ?? null;
    }

    /**
     * Returns modules that should appear in the navigation bar.
     *
     * @return array<string, array<string, mixed>>
     */
    public function navbarItems(): array
    {
        return array_filter($this->modules, static fn($m) => !empty($m['navbar']));
    }

    /**
     * Resolves the effective navbar path for a module.
     *
     * Resolution order:
     *   1. Explicit 'path' key.
     *   2. For 'routes' modules: first GET route without dynamic segments ({param}).
     *   3. Default: '/{slug}'.
     *
     * Returns null when a routes module has no static GET route — the navbar
     * template should render the item as disabled in that case.
     *
     * @param  array<string, mixed> $module
     * @return string|null
     */
    private static function resolvePath(array $module): ?string
    {
        if (isset($module['path'])) {
            return $module['path'];
        }

        if (!empty($module['routes'])) {
            $candidates = [];
            foreach ($module['routes'] as $endpoint => $handler) {
                [$method, $path] = explode(' ', $endpoint, 2);
                if (strtoupper($method) === 'GET' && !str_contains($path, '{')) {
                    $candidates[] = $path;
                }
            }
            if (!empty($candidates)) {
                usort($candidates, fn($a, $b) => strlen($a) <=> strlen($b));
                $shortest = $candidates[0];
                return '/' . $module['slug'] . ($shortest === '/' ? '' : $shortest);
            }
        }

        return '/' . $module['slug'];
    }

    /**
     * Returns modules that define at least one scheduled task.
     *
     * @return array<string, array<string, mixed>>
     */
    public function scheduledModules(): array
    {
        return array_filter($this->modules, static fn($m) => !empty($m['schedule']));
    }
}
