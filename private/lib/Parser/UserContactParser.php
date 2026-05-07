<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/UserContactParser.php
 * Encode, decode, and normalize helpers for the user contact-profiles JSON column.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Lib\Format\Json;

/**
 * Static helpers for the user contact-profiles JSON column.
 *
 * Handles round-trip JSON serialization and normalization of the contact_profiles
 * column, which stores an ordered list of typed contact/social links (e.g. email,
 * GitHub, Mastodon) as {type, value} pairs.
 */
final class UserContactParser
{
    private const MAX_PROFILES = 20;

    /**
     * Decodes a raw JSON contact-profiles column value into a typed array.
     *
     * Returns an empty array on any decode or normalization error.
     *
     * @param mixed $raw Raw column value from the database.
     * @return array<int, array{type: string, value: string}>
     */
    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = Json::decode($raw, 32);
        if (!is_array($decoded)) {
            return [];
        }

        return self::normalize($decoded);
    }

    /**
     * Encodes a normalized contact-profiles array to a JSON string for persistence.
     *
     * Returns null when the array is empty so callers can store a NULL column cleanly.
     *
     * @param array<int, array{type: string, value: string}> $profiles Normalized profiles array.
     * @return string|null JSON string, or null when profiles is empty.
     */
    public static function encode(array $profiles): ?string
    {
        if ($profiles === []) {
            return null;
        }

        return Json::encode($profiles, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Normalizes and deduplicates a raw contact-profiles array.
     *
     * Cleans type slugs (lowercase, hyphens only), trims values, truncates long entries,
     * removes duplicates, and sorts by type then value for stable ordering.
     *
     * @param array<int, mixed> $profiles Raw profile entries from form input or DB decode.
     * @return array<int, array{type: string, value: string}>
     */
    public static function normalize(array $profiles): array
    {
        $normalized = [];

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $type = strtolower(trim((string) ($profile['type'] ?? '')));
            $value = trim((string) ($profile['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            // Normalize type slug: lowercase, hyphens only, no leading/trailing hyphens.
            $type = preg_replace('/[^a-z0-9-]+/', '-', $type) ?? '';
            $type = trim($type, '-');
            $type = preg_replace('/-+/', '-', $type) ?? '';
            if ($type === '') {
                continue;
            }

            if (mb_strlen($type) > 80) {
                $type = mb_substr($type, 0, 80);
            }
            if (mb_strlen($value) > 255) {
                $value = mb_substr($value, 0, 255);
            }
            if ($value === '') {
                continue;
            }

            // Deduplicate by case-insensitive type+value composite key.
            $dedupeKey = strtolower($type . "\n" . $value);
            $normalized[$dedupeKey] = [
                'type' => $type,
                'value' => $value,
            ];

            if (count($normalized) >= self::MAX_PROFILES) {
                break;
            }
        }

        $result = array_values($normalized);
        usort(
            $result,
            static function (array $left, array $right): int {
                $leftType = strtolower(trim((string) ($left['type'] ?? '')));
                $rightType = strtolower(trim((string) ($right['type'] ?? '')));
                if ($leftType !== $rightType) {
                    return $leftType <=> $rightType;
                }

                $leftValue = strtolower(trim((string) ($left['value'] ?? '')));
                $rightValue = strtolower(trim((string) ($right['value'] ?? '')));
                return $leftValue <=> $rightValue;
            }
        );

        return $result;
    }
}
