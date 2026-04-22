<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Generic CSRF helper that delegates persistence to a token store contract.
 */
final class Csrf
{
    private string $tokenKey;
    private int $tokenBytes;
    private CsrfToken $store;

    public function __construct(
        ?CsrfToken $store = null,
        string $tokenKey = '_raven_csrf',
        int $tokenBytes = 32
    ) {
        $this->store = $store ?? new SessionToken();
        $this->tokenKey = trim($tokenKey) !== '' ? $tokenKey : '_raven_csrf';
        $this->tokenBytes = max(16, min(128, $tokenBytes));
    }

    public function token(): string
    {
        $token = $this->store->get($this->tokenKey);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes($this->tokenBytes));
        $this->store->set($this->tokenKey, $token);
        return $token;
    }

    public function rotate(): string
    {
        $this->store->remove($this->tokenKey);
        return $this->token();
    }

    public function field(string $fieldName = '_csrf'): string
    {
        $name = htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . $name . '" value="' . $token . '">';
    }

    public function validate(?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($this->token(), $submitted);
    }
}
