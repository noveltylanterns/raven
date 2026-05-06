<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/EmailObfuscate.php
 * Privacy-masking helper for email addresses shown in public-facing UI.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Lib\Mail\Address;

/**
 * Returns obfuscated forms of email addresses for display in login and 2FA flows.
 *
 * Delegates masking logic to the canonical Address helper so the mask format
 * is defined in one place and reused here without duplication.
 */
final class EmailObfuscate
{
    /**
     * Returns a privacy-masked version of an email address for display.
     *
     * Wraps Address::mask() so callers in security flows do not need to import the
     * mail layer directly.
     *
     * @param string $email Email address to mask.
     * @return string Masked address (e.g. `jo***n@g***.com`), or empty string when invalid.
     */
    public static function mask(string $email): string
    {
        return Address::mask($email);
    }
}
