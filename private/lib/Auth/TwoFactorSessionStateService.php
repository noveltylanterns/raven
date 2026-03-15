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
    private const SESSION_2FA_PENDING_EMAIL_CHALLENGES = '_raven_2fa_pending_email_challenges';
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
        unset($_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES]);
        unset($_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]);
    }

    public function markVerified(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS],
            $_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES]
        );
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
        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS],
            $_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES]
        );
    }

    public function clearAll(): void
    {
        unset(
            $_SESSION[self::SESSION_2FA_PENDING_USER_ID],
            $_SESSION[self::SESSION_2FA_PENDING_METHODS],
            $_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES],
            $_SESSION[self::SESSION_2FA_VERIFIED_USER_ID]
        );
    }

    public function storeEmailCodeChallenge(
        int $userId,
        string $methodKey,
        string $email,
        string $codeHash,
        int $issuedAt,
        int $expiresAt
    ): void {
        $methodKey = trim($methodKey);
        $email = strtolower(trim($email));
        $codeHash = trim($codeHash);
        if (
            $userId <= 0
            || $methodKey === ''
            || $email === ''
            || $codeHash === ''
            || $issuedAt <= 0
            || $expiresAt <= $issuedAt
        ) {
            return;
        }

        $map = $this->emailChallengeMap();
        $map[$methodKey] = [
            'user_id' => $userId,
            'method_key' => $methodKey,
            'email' => $email,
            'code_hash' => $codeHash,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];

        $_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES] = $map;
    }

    /**
     * @return array{
     *   user_id: int,
     *   method_key: string,
     *   email: string,
     *   code_hash: string,
     *   issued_at: int,
     *   expires_at: int
     * }|null
     */
    public function pendingEmailCodeChallenge(int $userId, string $methodKey): ?array
    {
        $methodKey = trim($methodKey);
        if ($userId <= 0 || $methodKey === '') {
            return null;
        }

        $map = $this->emailChallengeMap();
        $challenge = $map[$methodKey] ?? null;
        if (!is_array($challenge)) {
            return null;
        }

        $challengeUserId = (int) ($challenge['user_id'] ?? 0);
        if ($challengeUserId !== $userId) {
            return null;
        }

        $email = strtolower(trim((string) ($challenge['email'] ?? '')));
        $codeHash = trim((string) ($challenge['code_hash'] ?? ''));
        $issuedAt = (int) ($challenge['issued_at'] ?? 0);
        $expiresAt = (int) ($challenge['expires_at'] ?? 0);
        if (
            $email === ''
            || $codeHash === ''
            || $issuedAt <= 0
            || $expiresAt <= $issuedAt
        ) {
            return null;
        }

        return [
            'user_id' => $challengeUserId,
            'method_key' => $methodKey,
            'email' => $email,
            'code_hash' => $codeHash,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
    }

    public function clearEmailCodeChallenge(string $methodKey = ''): void
    {
        $methodKey = trim($methodKey);
        if ($methodKey === '') {
            unset($_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES]);
            return;
        }

        $map = $this->emailChallengeMap();
        if (!array_key_exists($methodKey, $map)) {
            return;
        }

        unset($map[$methodKey]);
        if ($map === []) {
            unset($_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES]);
            return;
        }

        $_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES] = $map;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emailChallengeMap(): array
    {
        $raw = $_SESSION[self::SESSION_2FA_PENDING_EMAIL_CHALLENGES] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            $methodKey = trim((string) $key);
            if ($methodKey === '' || !is_array($value)) {
                continue;
            }

            $map[$methodKey] = $value;
        }

        return $map;
    }
}
