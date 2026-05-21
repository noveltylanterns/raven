<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/UserString.php
 * Generates normalized random public user selector strings.
 * Docs: https://lanterns.io/raven
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

    /**
     * Normalizes a length value to a valid positive integer capped at 128.
     *
     * Falls back to the default when the value is non-numeric or less than 1.
     *
     * @param mixed $value Raw length value (numeric string or integer).
     * @param int $default Fallback length when normalization fails.
     * @return int Normalized length in the range [1, 128].
     */
    public function normalizeLength(mixed $value, int $default = self::DEFAULT_LENGTH): int
    {
        $length = is_numeric($value) ? (int) $value : $default;
        // Non-positive lengths fall back to default to preserve usable output.
        if ($length < 1) {
            $length = $default;
        }

        return min($length, 128);
    }

    /**
     * Generates a random user string that is confirmed unique by the provided existence check.
     *
     * Retries up to 256 times before throwing. In practice collisions are astronomically rare
     * at the default 28-character length.
     *
     * @param int $length Desired string length; normalized via `normalizeLength()`.
     * @param callable(string): bool $exists Callback that returns true when a candidate already exists.
     * @return string Unique random string of the requested length.
     * @throws \RuntimeException When a unique candidate cannot be found within the retry limit.
     */
    public function generateUnique(int $length, callable $exists): string
    {
        $normalizedLength = $this->normalizeLength($length);

        for ($attempt = 0; $attempt < 256; $attempt++) {
            $candidate = $this->generate($normalizedLength);
            // Return immediately once the caller confirms uniqueness.
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Failed to generate a unique user string.');
    }

    /**
     * Generates a single random string from the alphanumeric alphabet without uniqueness checking.
     *
     * @param int $length Desired string length; normalized via `normalizeLength()`.
     * @return string Random alphanumeric string of the requested length.
     */
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
