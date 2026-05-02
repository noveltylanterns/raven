<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/LoginIdentifier.php
 * Login identifier mode resolution and normalization helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Resolves login identifier mode from config and normalizes submitted login identifiers.
 *
 * Used by auth controllers and user-management controllers to determine whether the
 * site is configured for email or username login, and to normalize submitted values
 * before credential lookups or form validation.
 */
final class LoginIdentifier
{
    /**
     * Returns the configured login identifier mode for this site.
     *
     * Reads `user.auth.method` from config and normalizes it to `email` or `username`.
     * Defaults to `email` when the configured value is absent or unrecognized.
     *
     * @param Config $config Shared configuration service.
     * @return string Either `email` or `username`.
     */
    public function modeFromConfig(Config $config): string
    {
        $mode = strtolower(trim((string) $config->get('user.auth.method', 'email')));
        if (!in_array($mode, ['email', 'username'], true)) {
            return 'email';
        }

        return $mode;
    }

    /**
     * Normalizes a submitted login identifier according to the active identifier mode.
     *
     * In email mode the value must pass email validation; in username mode it is
     * normalized as a username-or-email (whichever parses first).
     *
     * @param InputSanitizer $input    Shared payload sanitizer.
     * @param string         $mode     Active identifier mode (`email` or `username`).
     * @param string         $rawIdentifier Raw value submitted by the user.
     * @return string|null Normalized identifier, or null when the value is invalid for the mode.
     */
    public function normalizeForMode(InputSanitizer $input, string $mode, string $rawIdentifier): ?string
    {
        $normalizedText = $input->text($rawIdentifier, 254);
        if ($normalizedText === '') {
            return null;
        }

        if ($mode === 'email') {
            $normalizedEmail = $input->email($normalizedText);
            return ($normalizedEmail !== null && $normalizedEmail !== '') ? $normalizedEmail : null;
        }

        return $this->normalizeUsernameOrEmail($input, $normalizedText);
    }

    /**
     * Normalizes a raw value as a username when it passes username rules, or as an email otherwise.
     *
     * Used in username-mode login flows where the user may submit either a username or
     * an email address as their identifier.
     *
     * @param InputSanitizer $input    Shared payload sanitizer.
     * @param string         $rawValue Raw username or email submitted by the user.
     * @return string|null Normalized username or email, or null when neither form is valid.
     */
    public function normalizeUsernameOrEmail(InputSanitizer $input, string $rawValue): ?string
    {
        $normalizedText = $input->text($rawValue, 254);
        if ($normalizedText === '') {
            return null;
        }

        $normalizedUsername = $input->username($normalizedText);
        if ($normalizedUsername !== null && $normalizedUsername !== '') {
            return $normalizedUsername;
        }

        $normalizedEmail = $input->email($normalizedText);
        if ($normalizedEmail !== null && $normalizedEmail !== '') {
            return $normalizedEmail;
        }

        return null;
    }
}
