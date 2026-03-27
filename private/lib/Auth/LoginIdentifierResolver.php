<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Resolves login identifier mode and normalizes submitted identifiers.
 */
final class LoginIdentifierResolver
{
    public function modeFromConfig(Config $config): string
    {
        $mode = strtolower(trim((string) $config->get('user.auth.method', 'email')));
        if (!in_array($mode, ['email', 'username'], true)) {
            return 'email';
        }

        return $mode;
    }

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
