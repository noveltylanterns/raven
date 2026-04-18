<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Session-backed flash-message helper.
 */
final class SessionFlash
{
    private string $sessionKey;

    public function __construct(string $sessionKey = '_raven_flash')
    {
        $this->sessionKey = trim($sessionKey) !== '' ? $sessionKey : '_raven_flash';
    }

    public function put(string $key, string $value): void
    {
        $store = &$this->sessionStore();
        $store[$key] = $value;
    }

    public function pull(string $key): ?string
    {
        $store = &$this->sessionStore();
        $value = $store[$key] ?? null;
        unset($store[$key]);
        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int, string> $values
     */
    public function putList(string $key, array $values): void
    {
        $store = &$this->sessionStore();
        $store[$key] = array_values($values);
    }

    /**
     * @return array<int, string>|null
     */
    public function pullList(string $key): ?array
    {
        $store = &$this->sessionStore();
        $value = $store[$key] ?? null;
        unset($store[$key]);
        if (!is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function &sessionStore(): array
    {
        if (!isset($_SESSION[$this->sessionKey]) || !is_array($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = [];
        }

        return $_SESSION[$this->sessionKey];
    }
}
