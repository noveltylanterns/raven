<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

use RuntimeException;

final class PhpSessionTokenStore implements CsrfTokenStoreInterface
{
    public function get(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Unable to set CSRF token before session start.');
        }

        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        unset($_SESSION[$key]);
    }
}

