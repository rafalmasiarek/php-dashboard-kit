<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKit\Transport;

use AuthKit\Transport\TokenTransportInterface;

/**
 * Session transport that defers session_start() until the session is first accessed.
 *
 * The default PhpSessionTransport calls session_start() inside initialize(), which
 * is invoked by Auth::__construct(). When Auth is resolved eagerly during container
 * bootstrap — before any request middleware runs — any PHP notice or deprecation
 * written to output by the DI container causes "headers already sent", making
 * session_start() fail.
 *
 * This transport skips initialize() as a no-op. The session is started lazily on the
 * first actual read or write, by which point the SessionMiddleware has already run
 * and the session is active — making this a safe no-op itself.
 *
 * @package rafalmasiarek\DashboardKit\Transport
 */
final class LazyPhpSessionTransport implements TokenTransportInterface
{
    /** @var string $_SESSION key used to store the auth token. */
    private string $key;

    /**
     * @param string $key $_SESSION key (default: 'auth_token').
     */
    public function __construct(string $key = 'auth_token')
    {
        $this->key = $key;
    }

    /**
     * No-op — session is started lazily on first access.
     *
     * @return void
     */
    public function initialize(): void {}

    /**
     * Returns the stored token, starting the session if not yet active.
     *
     * @return string|null
     */
    public function get(): ?string
    {
        $this->ensureSession();

        $token = $_SESSION[$this->key] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Stores the token in the session, starting it if not yet active.
     *
     * @param  string $token
     * @return void
     */
    public function set(string $token): void
    {
        $this->ensureSession();

        $_SESSION[$this->key] = $token;
    }

    /**
     * Removes the token from the session, starting it if not yet active.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->ensureSession();

        unset($_SESSION[$this->key]);
    }

    /**
     * Starts the PHP session if not yet active.
     *
     * @return void
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
