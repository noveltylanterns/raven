<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared 2FA method type/status/label and dedupe rules.
 */
final class TwoFactorMethodRules
{
    /** @var array<int, string> */
    private const TYPES = ['totp', 'recovery', 'webauthn', 'email'];

    /** @var array<int, string> */
    private const STATUSES = ['pending', 'confirmed', 'stub'];

    public static function normalizeType(string $type): string
    {
        return strtolower(trim($type));
    }

    public static function isKnownType(string $type): bool
    {
        return in_array(self::normalizeType($type), self::TYPES, true);
    }

    public static function defaultLabelForType(string $type): string
    {
        return match (self::normalizeType($type)) {
            'totp' => 'Authenticator App',
            'recovery' => 'Recovery Code',
            'webauthn' => 'Security Key',
            default => 'Email Code',
        };
    }

    public static function normalizeLabel(string $label, string $type, int $maxLength = 80): string
    {
        $normalized = trim($label);
        if ($normalized === '') {
            $normalized = self::defaultLabelForType($type);
        }

        if (mb_strlen($normalized) > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }

    public static function normalizeStatus(string $status, string $type): string
    {
        $normalizedStatus = strtolower(trim($status));
        if (in_array($normalizedStatus, self::STATUSES, true)) {
            return $normalizedStatus;
        }

        $normalizedType = self::normalizeType($type);
        if ($normalizedType === 'totp') {
            return 'pending';
        }
        if ($normalizedType === 'recovery') {
            return 'confirmed';
        }

        return 'stub';
    }

    public static function statusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'confirmed' => 'Confirmed',
            'pending' => 'Pending',
            default => 'Stub',
        };
    }

    public static function dedupeKey(string $type, string $label, string $value): string
    {
        return strtolower(
            self::normalizeType($type) . "\n" . trim($label) . "\n" . trim($value)
        );
    }
}

