<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/EmailValidate.php
 * Primitive email validation and normalization helpers for login flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Pure static helpers for normalizing and validating email input in auth contexts.
 *
 * These primitives are used by login, 2FA email challenge, and preference flows.
 * No session or DB access; all methods depend only on built-in PHP functions.
 */
final class EmailValidate
{
    /**
     * Normalizes and validates one email address string.
     *
     * Returns null when the address is empty or fails PHP's format filter so callers
     * can treat null as an unambiguous rejection signal.
     *
     * @param string $email Raw email address from user input.
     * @return string|null Lowercase-trimmed address on success, null on rejection.
     */
    public static function normalize(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * Normalizes a submitted numeric email code to its canonical 8-digit form.
     *
     * Strips all non-digit characters and returns the result only when exactly 8
     * digits remain; otherwise returns an empty string so callers can treat '' as
     * a rejection signal.
     *
     * @param string $submittedCode Raw code string from the login form.
     * @return string Eight-digit code string, or empty string when format is invalid.
     */
    public static function normalizeCode(string $submittedCode): string
    {
        $normalized = preg_replace('/\D+/', '', $submittedCode) ?? '';
        return strlen($normalized) === 8 ? $normalized : '';
    }
}
