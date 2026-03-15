<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Shared session-state helper for interactive 2FA challenge lifecycle.
 */
final class TwoFactorSessionStateService
{
    private const SESSION_2FA_PENDING_USER_ID = '_raven_2fa_pending_user_id';
    private const SESSION_2FA_PENDING_METHODS = '_raven_2fa_pending_methods';
    private const SESSION_2FA_VERIFIED_USER_ID = '_raven_2fa_verified_user_id';

    /**
     * @param array<int, array<string, mixed>> $methods
     */
    public function beginChallenge(int $userId, array $methods): void
    {
        if ($userId <= 0) {
            return;
        }

        $_SESSION[self::SESSION_2FA_PENDING_USER_ID] = $userId;
        $_SESSION[self::SESSION_2FA_PENDING_METHODS] = $methods;
        unset($_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]);
    }

    public function markVerified(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset($_SESSION[self::SESSION_2FA_PENDING_USER_ID], $_SESSION[self::SESSION_2FA_PENDING_METHODS]);
        $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID] = $userId;
    }

    public function pendingUserId(): ?int
    {
        $pendingUserId = (int) ($_SESSION[self::SESSION_2FA_PENDING_USER_ID] ?? 0);
        return $pendingUserId > 0 ? $pendingUserId : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingMethods(): array
    {
        $raw = $_SESSION[self::SESSION_2FA_PENDING_METHODS] ?? null;
        return is_array($raw) ? array_values($raw) : [];
    }

    public function isVerifiedForUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return (int) ($_SESSION[self::SESSION_2FA_VERIFIED_USER_ID] ?? 0) === $userId;
    }

    public function clearChallenge(): void
    {
        unset($_SESSION[self::SESSION_2FA_PENDING_USER_ID], $_SESSION[self::SESSION_2FA_PENDING_METHODS]);
    }

    public function clearAll(): void
    {
        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS],
            $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]
        );
    }
}
