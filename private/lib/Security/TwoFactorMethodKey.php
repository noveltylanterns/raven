<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared key format helpers for interactive 2FA method selection.
 */
final class TwoFactorMethodKey
{
    private const RECOVERY_POOL_KEY = 'recovery:pool';
    private const EMAIL_POOL_KEY = 'email:pool';

    public static function forTotpSecret(string $secret): string
    {
        return 'totp:' . sha1(TotpService::normalizeSecret($secret));
    }

    public static function forRecoveryPhrase(string $phrase): string
    {
        return 'recovery:' . sha1(RecoveryPhrase::normalize($phrase));
    }

    public static function forRecoveryHash(string $recoveryHash): string
    {
        return 'recovery:' . sha1(trim($recoveryHash));
    }

    public static function forWebauthnCredentialId(string $credentialId): string
    {
        return 'webauthn:' . trim($credentialId);
    }

    public static function forEmailAddress(string $email): string
    {
        return 'email:' . sha1(strtolower(trim($email)));
    }

    public static function recoveryPool(): string
    {
        return self::RECOVERY_POOL_KEY;
    }

    public static function emailPool(): string
    {
        return self::EMAIL_POOL_KEY;
    }

    public static function isRecoveryPool(string $methodKey): bool
    {
        return trim(strtolower($methodKey)) === self::RECOVERY_POOL_KEY;
    }

    public static function isEmailPool(string $methodKey): bool
    {
        return trim(strtolower($methodKey)) === self::EMAIL_POOL_KEY;
    }

    public static function extractWebauthnCredentialId(string $methodKey): string
    {
        $methodKey = trim($methodKey);
        if (!str_starts_with($methodKey, 'webauthn:')) {
            return '';
        }

        return trim(substr($methodKey, strlen('webauthn:')));
    }
}
