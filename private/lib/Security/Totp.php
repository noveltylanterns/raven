<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/Totp.php
 * Shared TOTP secret, code, and provisioning-URI helpers for 2FA flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use RobThree\Auth\Algorithm;
use RobThree\Auth\TwoFactorAuth;

// Load robthree/twofactorauth package handler on first use.
(static function (): void {
    $handler = dirname(__DIR__) . '/Composer/robthree/twofactorauth.php';
    if (is_file($handler)) {
        require_once $handler;
    }
})();

/**
 * Shared TOTP helpers used by panel setup and auth verification paths.
 */
final class Totp
{
    private const SECRET_MIN_LENGTH = 32;
    private const SECRET_MAX_LENGTH = 128;
    private const MODERN_SECRET_BITS = 160;
    private const MODERN_DIGITS = 8;
    private const PERIOD_SECONDS = 30;

    /**
     * Strips non-base32 characters and uppercases a TOTP secret string.
     *
     * @param string $secret Raw secret value from user input or storage.
     * @return string Cleaned uppercase base32 secret.
     */
    public static function normalizeSecret(string $secret): string
    {
        $normalized = strtoupper(trim($secret));
        return preg_replace('/[^A-Z2-7]/', '', $normalized) ?? '';
    }

    /**
     * Returns true when the normalized secret meets the required base32 length constraints.
     *
     * @param string $secret Secret string; normalization is applied before the check.
     * @return bool True when the secret is a valid base32 string of acceptable length.
     */
    public static function isValidSecret(string $secret): bool
    {
        return preg_match(
            '/^[A-Z2-7]{' . self::SECRET_MIN_LENGTH . ',' . self::SECRET_MAX_LENGTH . '}$/',
            self::normalizeSecret($secret)
        ) === 1;
    }

    /**
     * Strips all non-digit characters from a submitted TOTP code.
     *
     * @param string $code Raw code from user input, potentially with spaces or dashes.
     * @return string Digit-only code string.
     */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D+/', '', trim($code)) ?? '';
    }

    /**
     * Returns true when the normalized code is exactly the expected digit count.
     *
     * @param string $code Code string; normalization is applied before the check.
     * @return bool True when the code contains exactly MODERN_DIGITS digits.
     */
    public static function isValidCode(string $code): bool
    {
        return preg_match('/^\d{' . self::MODERN_DIGITS . '}$/', self::normalizeCode($code)) === 1;
    }

    /**
     * Generates a new cryptographically random TOTP secret using the vendor library.
     *
     * Returns null when the vendor library is unavailable or generation fails.
     *
     * @param string $issuer Display name embedded in the provisioning URI.
     * @return string|null Normalized base32 secret, or null on failure.
     */
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

    /**
     * Verifies a submitted TOTP code against a secret using the vendor library.
     *
     * A clock tolerance window of 1 allows one step (±30 s) to account for submission lag.
     * Returns false when the secret or code is invalid or the vendor library is unavailable.
     *
     * @param string $secret Base32 TOTP secret.
     * @param string $code Submitted code from the user.
     * @param int $window Clock drift tolerance in steps; clamped to ≥ 0.
     * @param string $issuer TOTP issuer label used during verification.
     * @return bool True when the code is valid for the given secret within the clock window.
     */
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

    /**
     * Builds the otpauth:// provisioning URI for QR code display during TOTP setup.
     *
     * Returns an empty string when the secret is invalid or the vendor library is unavailable.
     *
     * @param string $issuer Site or app name shown in the authenticator app.
     * @param string $accountEmail User email address shown in the authenticator app.
     * @param string $secret Base32 TOTP secret to encode in the URI.
     * @return string otpauth:// URI, or empty string on failure.
     */
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
