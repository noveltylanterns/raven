<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/Csrf.php
 * Generic CSRF token generation, rotation, field rendering, and validation helper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Lib\Auth\SessionToken;

/**
 * Generic CSRF helper that delegates persistence to a token store contract.
 */
final class Csrf
{
    private string $tokenKey;
    private int $tokenBytes;
    private CsrfToken $store;

    /**
     * @param CsrfToken|null $store Token persistence store; defaults to session-backed SessionToken.
     * @param string $tokenKey Session/store key used to persist the CSRF token.
     * @param int $tokenBytes Byte length of generated tokens; clamped to [16, 128].
     */
    public function __construct(
        ?CsrfToken $store = null,
        string $tokenKey = '_raven_csrf',
        int $tokenBytes = 32
    ) {
        $this->store = $store ?? new SessionToken();
        $this->tokenKey = trim($tokenKey) !== '' ? $tokenKey : '_raven_csrf';
        $this->tokenBytes = max(16, min(128, $tokenBytes));
    }

    /**
     * Returns the current CSRF token, generating and persisting a new one if none exists.
     *
     * @return string Hex-encoded CSRF token.
     */
    public function token(): string
    {
        $token = $this->store->get($this->tokenKey);
        // Reuse persisted token when present to keep one stable token per session window.
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes($this->tokenBytes));
        $this->store->set($this->tokenKey, $token);
        return $token;
    }

    /**
     * Invalidates the current token and returns a freshly generated replacement.
     *
     * Use this after a successful form submission to prevent token reuse.
     *
     * @return string New hex-encoded CSRF token.
     */
    public function rotate(): string
    {
        $this->store->remove($this->tokenKey);
        return $this->token();
    }

    /**
     * Renders an HTML hidden input element carrying the current CSRF token.
     *
     * @param string $fieldName Name attribute for the hidden input element.
     * @return string HTML hidden input tag.
     */
    public function field(string $fieldName = '_csrf'): string
    {
        $name = htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . $name . '" value="' . $token . '">';
    }

    /**
     * Validates a submitted CSRF token using a constant-time comparison.
     *
     * @param string|null $submitted Token value from the submitted form.
     * @return bool True when the submitted token matches the stored token.
     */
    public function validate(?string $submitted): bool
    {
        // Missing or empty submissions fail fast before constant-time comparison.
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($this->token(), $submitted);
    }
}
