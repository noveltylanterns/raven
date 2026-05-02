<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/PasswordValidator.php
 * Shared password-change validation helper for account settings flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared password-change validation helper for panel/public account flows.
 */
final class PasswordValidator
{
    /**
     * Validates a new-password-change submission and returns a list of user-facing error strings.
     *
     * Returns an empty array when both fields are empty (no change requested) or when all rules pass.
     *
     * @param string $newPassword Submitted new password value.
     * @param string $confirmNewPassword Submitted confirmation value.
     * @param int $minimumLength Minimum required password character length.
     * @return array<int, string> Validation errors; empty array on success.
     */
    public function validateNewPasswordChange(
        string $newPassword,
        string $confirmNewPassword,
        int $minimumLength = 8
    ): array {
        $errors = [];

        if ($newPassword === '' && $confirmNewPassword === '') {
            return $errors;
        }

        if ($newPassword === '' || $confirmNewPassword === '') {
            $errors[] = 'Both new password fields are required to change password.';
            return $errors;
        }

        if (!hash_equals($newPassword, $confirmNewPassword)) {
            $errors[] = 'New password and confirm new password must match.';
        }

        if (strlen($newPassword) < $minimumLength) {
            $errors[] = 'New password must be at least ' . $minimumLength . ' characters.';
        }

        return $errors;
    }
}
