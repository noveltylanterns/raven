<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/SessionToken.php
 * PHP session-backed token storage for the shared CSRF helper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Security\CsrfToken;
use RuntimeException;

/**
 * Stores CSRF tokens in the active PHP session.
 */
final class SessionToken implements CsrfToken
{
    /**
     * Fetches one token value from session storage.
     *
     * @param string $key Session key for the token value.
     * @return string|null Stored token string when present.
     */
    public function get(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Persists one token value into session storage.
     *
     * @param string $key Session key for the token value.
     * @param string $value Token string to persist.
     * @return void
     * @throws RuntimeException When no PHP session is active yet.
     */
    public function set(string $key, string $value): void
    {
        // Token writes require an active session storage context.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Unable to set CSRF token before session start.');
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Removes one token value from session storage.
     *
     * @param string $key Session key for the token value.
     * @return void
     */
    public function remove(string $key): void
    {
        // Treat token removal as a no-op before session startup.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        unset($_SESSION[$key]);
    }
}
