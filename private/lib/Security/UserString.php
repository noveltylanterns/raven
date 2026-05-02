<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/UserString.php
 * Generates normalized random public user selector strings.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use RuntimeException;

/**
 * Generates normalized random public user selector strings.
 */
final class UserString
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const DEFAULT_LENGTH = 28;

    public function normalizeLength(mixed $value, int $default = self::DEFAULT_LENGTH): int
    {
        $length = is_numeric($value) ? (int) $value : $default;
        if ($length < 1) {
            $length = $default;
        }

        return min($length, 128);
    }

    /**
     * @param callable(string): bool $exists
     */
    public function generateUnique(int $length, callable $exists): string
    {
        $normalizedLength = $this->normalizeLength($length);

        for ($attempt = 0; $attempt < 256; $attempt++) {
            $candidate = $this->generate($normalizedLength);
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Failed to generate a unique user string.');
    }

    public function generate(int $length): string
    {
        $normalizedLength = $this->normalizeLength($length);
        $maxIndex = strlen(self::ALPHABET) - 1;
        $value = '';

        for ($index = 0; $index < $normalizedLength; $index++) {
            $value .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $value;
    }
}
