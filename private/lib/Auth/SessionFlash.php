<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/SessionFlash.php
 * Session-backed flash-message store shared by panel and public route controllers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Session-backed flash-message helper.
 */
final class SessionFlash
{
    private string $sessionKey;

    /**
     * @param string $sessionKey Top-level key under which flash data is stored in $_SESSION.
     * @return void
     */
    public function __construct(string $sessionKey = '_raven_flash')
    {
        $this->sessionKey = trim($sessionKey) !== '' ? $sessionKey : '_raven_flash';
    }

    /**
     * Stores a single string flash value under a key.
     *
     * @param string $key Flash message key.
     * @param string $value Value to store.
     * @return void
     */
    public function put(string $key, string $value): void
    {
        $store = &$this->sessionStore();
        $store[$key] = $value;
    }

    /**
     * Reads and removes a single string flash value.
     *
     * @param string $key Flash message key.
     * @return string|null Stored value, or null when no entry exists.
     */
    public function pull(string $key): ?string
    {
        $store = &$this->sessionStore();
        $value = $store[$key] ?? null;
        unset($store[$key]);
        return is_string($value) ? $value : null;
    }

    /**
     * Stores an ordered list of string flash values under a key.
     *
     * @param string $key Flash message key.
     * @param array<int, string> $values Values to store.
     * @return void
     */
    public function putList(string $key, array $values): void
    {
        $store = &$this->sessionStore();
        $store[$key] = array_values($values);
    }

    /**
     * Reads and removes an ordered list of string flash values.
     *
     * @param string $key Flash message key.
     * @return array<int, string>|null Stored list, or null when no entry exists.
     */
    public function pullList(string $key): ?array
    {
        $store = &$this->sessionStore();
        $value = $store[$key] ?? null;
        unset($store[$key]);
        // Only array payloads are valid for list-based flash entries.
        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        // Keep only string entries when normalizing pulled flash lists.
        foreach ($value as $item) {
            // Skip non-string list members.
            if (is_string($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function &sessionStore(): array
    {
        // Initialize missing/malformed flash store to an empty array bucket.
        if (!isset($_SESSION[$this->sessionKey]) || !is_array($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = [];
        }

        return $_SESSION[$this->sessionKey];
    }
}
