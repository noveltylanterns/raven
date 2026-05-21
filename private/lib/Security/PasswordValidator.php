<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/PasswordValidator.php
 * Shared password-change validation helper for account settings flows.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared password-change validation helper for panel/public account flows.
 */
final class PasswordValidator
{
    /**
     * Validates a new-password submission and returns a list of user-facing error strings.
     *
     * Returns an empty array when both fields are empty (no change requested) or when all rules pass.
     *
     * @param string $newPass Submitted new password value.
     * @param string $confirmNewPass Submitted confirmation value.
     * @param int $minimumLength Minimum required password character length.
     * @return array<int, string> Validation errors; empty array on success.
     */
    public function validateNewPass(
        string $newPass,
        string $confirmNewPass,
        int $minimumLength = 8
    ): array {
        $errors = [];

        // Empty pair means caller did not request a password change.
        if ($newPass === '' && $confirmNewPass === '') {
            return $errors;
        }

        // Partial submissions are invalid; both fields must be present together.
        if ($newPass === '' || $confirmNewPass === '') {
            $errors[] = 'Both new password fields are required to change password.';
            return $errors;
        }

        // Constant-time comparison prevents mismatch checks from leaking timing data.
        if (!hash_equals($newPass, $confirmNewPass)) {
            $errors[] = 'New password and confirm new password must match.';
        }

        // Enforce minimum length after the equality check.
        if (strlen($newPass) < $minimumLength) {
            $errors[] = 'New password must be at least ' . $minimumLength . ' characters.';
        }

        return $errors;
    }
}
