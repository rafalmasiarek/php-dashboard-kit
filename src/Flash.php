<?php

namespace rafalmasiarek\DashboardKit;

/**
 * Session-based flash message store.
 *
 * Messages are written to the session and consumed exactly once on the next
 * request. Reading via get() clears the store.
 *
 * @package rafalmasiarek\DashboardKit
 */
class Flash
{
    /** @var array<string, list<string>>|null Lazily loaded from session. */
    private ?array $loaded = null;

    /**
     * Adds a flash message of the given type.
     *
     * @param string $type    Bootstrap alert type (success, danger, warning, info).
     * @param string $message Human-readable message text.
     */
    public function add(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_flash'][$type][] = $message;
    }

    /**
     * Reads and clears all flash messages for the current request.
     *
     * @return array<string, list<string>> Keyed by alert type.
     */
    public function get(): array
    {
        if ($this->loaded === null) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $this->loaded = $_SESSION['_flash'] ?? [];
            unset($_SESSION['_flash']);
        }
        return $this->loaded;
    }
}
