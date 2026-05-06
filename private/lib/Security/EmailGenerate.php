<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/EmailGenerate.php
 * Secure generation helpers for email-code 2FA challenges.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Generates cryptographically random numeric codes for email 2FA challenges.
 */
final class EmailGenerate
{
    /**
     * Generates one cryptographically random eight-digit numeric code.
     *
     * Uses random_int() for a uniform distribution across the full 0–99999999 range
     * and left-pads with zeros to guarantee exactly 8 digits.
     *
     * @return string Eight-digit zero-padded numeric code string.
     * @throws \Exception When the system CSPRNG fails to generate entropy.
     */
    public static function code(): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
