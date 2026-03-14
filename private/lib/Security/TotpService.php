<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

use RobThree\Auth\TwoFactorAuth;

/**
 * Shared TOTP helpers used by panel setup and auth verification paths.
 */
final class TotpService
{
    public static function normalizeSecret(string $secret): string
    {
        $normalized = strtoupper(trim($secret));
        return preg_replace('/[^A-Z2-7]/', '', $normalized) ?? '';
    }

    public static function isValidSecret(string $secret): bool
    {
        return preg_match('/^[A-Z2-7]{16,128}$/', self::normalizeSecret($secret)) === 1;
    }

    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D+/', '', trim($code)) ?? '';
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^\d{6,8}$/', self::normalizeCode($code)) === 1;
    }

    public static function generateSecret(string $issuer = 'Raven CMS'): ?string
    {
        if (!class_exists(TwoFactorAuth::class)) {
            return null;
        }

        try {
            $totp = new TwoFactorAuth(self::normalizeIssuer($issuer));
            $secret = self::normalizeSecret((string) $totp->createSecret());
            return self::isValidSecret($secret) ? $secret : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function verifyCode(
        string $secret,
        string $code,
        int $window = 1,
        string $issuer = 'Raven CMS'
    ): bool {
        $normalizedSecret = self::normalizeSecret($secret);
        $normalizedCode = self::normalizeCode($code);
        if (!self::isValidSecret($normalizedSecret) || !self::isValidCode($normalizedCode)) {
            return false;
        }

        if (!class_exists(TwoFactorAuth::class)) {
            return false;
        }

        try {
            $totp = new TwoFactorAuth(self::normalizeIssuer($issuer));
            return $totp->verifyCode($normalizedSecret, $normalizedCode, max(0, $window));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function provisioningUri(string $issuer, string $accountEmail, string $secret): string
    {
        $normalizedSecret = self::normalizeSecret($secret);
        if (!self::isValidSecret($normalizedSecret)) {
            return '';
        }

        $normalizedIssuer = self::normalizeIssuer($issuer);
        $account = self::normalizeAccountEmail($accountEmail);
        $label = rawurlencode($normalizedIssuer . ':' . $account);
        $encodedIssuer = rawurlencode($normalizedIssuer);

        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($normalizedSecret)
            . '&issuer=' . $encodedIssuer
            . '&digits=6&period=30';
    }

    private static function normalizeIssuer(string $issuer): string
    {
        $normalized = trim($issuer);
        return $normalized !== '' ? $normalized : 'Raven CMS';
    }

    private static function normalizeAccountEmail(string $accountEmail): string
    {
        $account = strtolower(trim($accountEmail));
        $validated = filter_var($account, FILTER_VALIDATE_EMAIL);
        return is_string($validated) && $validated !== '' ? $validated : 'account@local';
    }
}

