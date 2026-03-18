<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

use RobThree\Auth\Algorithm;
use RobThree\Auth\TwoFactorAuth;

/**
 * Shared TOTP helpers used by panel setup and auth verification paths.
 */
final class TotpService
{
    private const SECRET_MIN_LENGTH = 32;
    private const SECRET_MAX_LENGTH = 128;
    private const MODERN_SECRET_BITS = 160;
    private const MODERN_DIGITS = 8;
    private const PERIOD_SECONDS = 30;

    public static function normalizeSecret(string $secret): string
    {
        $normalized = strtoupper(trim($secret));
        return preg_replace('/[^A-Z2-7]/', '', $normalized) ?? '';
    }

    public static function isValidSecret(string $secret): bool
    {
        return preg_match(
            '/^[A-Z2-7]{' . self::SECRET_MIN_LENGTH . ',' . self::SECRET_MAX_LENGTH . '}$/',
            self::normalizeSecret($secret)
        ) === 1;
    }

    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D+/', '', trim($code)) ?? '';
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^\d{' . self::MODERN_DIGITS . '}$/', self::normalizeCode($code)) === 1;
    }

    public static function generateSecret(string $issuer = 'Raven CMS'): ?string
    {
        if (!class_exists(TwoFactorAuth::class)) {
            return null;
        }

        try {
            $totp = self::modernTotp(self::normalizeIssuer($issuer));
            $secret = self::normalizeSecret((string) $totp->createSecret(self::MODERN_SECRET_BITS));
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
            $normalizedIssuer = self::normalizeIssuer($issuer);
            return self::modernTotp($normalizedIssuer)->verifyCode(
                $normalizedSecret,
                $normalizedCode,
                max(0, $window)
            );
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
        $label = $normalizedIssuer . ':' . $account;

        try {
            return self::modernTotp($normalizedIssuer)->getQRText($label, $normalizedSecret);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function normalizeIssuer(string $issuer): string
    {
        $normalized = trim($issuer);
        return $normalized !== '' ? $normalized : 'Raven CMS';
    }

    private static function modernTotp(string $issuer): TwoFactorAuth
    {
        return new TwoFactorAuth(
            $issuer,
            self::MODERN_DIGITS,
            self::PERIOD_SECONDS,
            Algorithm::Sha256
        );
    }

    private static function normalizeAccountEmail(string $accountEmail): string
    {
        $account = strtolower(trim($accountEmail));
        $validated = filter_var($account, FILTER_VALIDATE_EMAIL);
        return is_string($validated) && $validated !== '' ? $validated : 'account@local';
    }
}
