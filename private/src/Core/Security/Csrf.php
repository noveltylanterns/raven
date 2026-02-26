<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Security/Csrf.php
 * Security utility for validation and protections.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Handle security-critical input and token checks in one reusable location.

declare(strict_types=1);

namespace Raven\Core\Security;

/**
 * Session-backed CSRF token helper for panel state-changing actions.
 */
final class Csrf
{
    /** Session key used to store the token. */
    private string $sessionKey = '_raven_csrf';

    /**
     * Returns active CSRF token, generating one if none exists.
     */
    public function token(): string
    {
        if (!isset($_SESSION[$this->sessionKey]) || !is_string($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$this->sessionKey];
    }

    /**
     * Returns ready-to-insert hidden field markup for forms.
     */
    public function field(): string
    {
        $token = htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    /**
     * Validates submitted token value against current session token.
     */
    public function validate(?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($this->token(), $submitted);
    }
}
