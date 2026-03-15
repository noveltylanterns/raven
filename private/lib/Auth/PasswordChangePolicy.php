<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Shared password-change validation policy for panel/public account flows.
 */
final class PasswordChangePolicy
{
    /**
     * @return array<int, string>
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
