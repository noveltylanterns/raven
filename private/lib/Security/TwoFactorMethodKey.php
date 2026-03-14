<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared key format helpers for interactive 2FA method selection.
 */
final class TwoFactorMethodKey
{
    public static function forTotpSecret(string $secret): string
    {
        return 'totp:' . sha1(TotpService::normalizeSecret($secret));
    }

    public static function forRecoveryPhrase(string $phrase): string
    {
        return 'recovery:' . sha1(RecoveryPhrase::normalize($phrase));
    }

    public static function forWebauthnCredentialId(string $credentialId): string
    {
        return 'webauthn:' . trim($credentialId);
    }

    public static function forEmailAddress(string $email): string
    {
        return 'email:' . sha1(strtolower(trim($email)));
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

