<?php

declare(strict_types=1);

namespace Raven\Lib\Taxonomy;

/**
 * Shared normalization helpers for file-backed taxonomy set records.
 */
final class TaxonomySetRecordPolicy
{
    public const ROOT_SET_ID = 0;
    public const ROOT_SET_SLUG = 'root';
    public const ROOT_SET_NAME = 'Default Set';

    public static function normalizeSetId(mixed $value): ?int
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^-?\d+$/', $normalized) !== 1) {
            return null;
        }

        $id = (int) $normalized;
        return $id >= self::ROOT_SET_ID ? $id : null;
    }

    public static function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';
        return substr($value, 0, 160);
    }

    public static function isValidSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim($value))) === 1;
    }

    /**
     * @return array<int, int|string>
     */
    public static function normalizeSelection(mixed $value, bool $defaultAll = true): array
    {
        $items = is_array($value) ? $value : [$value];
        $selection = [];

        foreach ($items as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $normalized = strtolower(trim((string) ($item ?? '')));
            if ($normalized === '') {
                continue;
            }

            if ($normalized === 'all') {
                return ['all'];
            }

            if (preg_match('/^\d+$/', $normalized) !== 1) {
                continue;
            }

            $setId = (int) $normalized;
            if ($setId < self::ROOT_SET_ID) {
                continue;
            }

            $selection[$setId] = $setId;
        }

        if ($selection === []) {
            return $defaultAll ? ['all'] : [];
        }

        ksort($selection, SORT_NUMERIC);
        return array_values($selection);
    }

    /**
     * @param array<int, int|string> $selection
     */
    public static function selectionIncludesAll(array $selection): bool
    {
        foreach ($selection as $item) {
            if (strtolower(trim((string) $item)) === 'all') {
                return true;
            }
        }

        return false;
    }
}
