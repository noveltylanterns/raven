<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/TotpVerify.php
 * Pure TOTP code verification against a stored set of 2FA method rows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Lib\Auth\Login2fa;

/**
 * Verifies a submitted TOTP code against confirmed TOTP methods.
 *
 * Extracted from Gatekeeper so callers that hold a method list can verify a
 * TOTP code without depending on the full authentication facade.
 */
final class TotpVerify
{
    /**
     * Returns true when the submitted code matches any confirmed TOTP method.
     *
     * Iterates all rows in the provided method list and returns true on the first
     * match. A clock tolerance of 1 step (±30 s) accounts for submission lag without
     * opening a large replay window.
     *
     * @param array<int, array<string, mixed>> $methods  2FA method rows decoded from the user preferences column.
     * @param string                           $submittedCode Six-digit code submitted by the user.
     * @param string                           $issuer   TOTP issuer label used during verification; defaults to 'Raven CMS'.
     * @return bool True when any confirmed TOTP method verifies the submitted code.
     */
    public static function verify(array $methods, string $submittedCode, string $issuer = 'Raven CMS'): bool
    {
        $submittedCode = Totp::normalizeCode($submittedCode);
        if (!Totp::isValidCode($submittedCode)) {
            return false;
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }

            $type   = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            $status = Login2fa::normalizeStatus((string) ($method['status'] ?? ''), $type);
            $secret = Totp::normalizeSecret((string) ($method['secret'] ?? ''));
            if ($type !== 'totp' || $status !== 'confirmed' || !Totp::isValidSecret($secret)) {
                continue;
            }

            if (Totp::verifyCode($secret, $submittedCode, 1, $issuer)) {
                return true;
            }
        }

        return false;
    }
}
