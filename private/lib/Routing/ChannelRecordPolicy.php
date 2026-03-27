<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Lib\Taxonomy\TaxonomySetRecordPolicy;

/**
 * Shared normalization policy for filesystem-backed channel records.
 */
final class ChannelRecordPolicy
{
    public const ROOT_CHANNEL_ID = 0;
    public const ROOT_CHANNEL_SLUG = 'root';
    public const ROOT_CHANNEL_NAME = '<root>';

    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim($slug))) === 1;
    }

    public static function isRootChannelId(int $id): bool
    {
        return $id === self::ROOT_CHANNEL_ID;
    }

    public static function isRootChannelSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === self::ROOT_CHANNEL_SLUG;
    }

    public static function normalizeEditorOverride(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $normalized
            : 'inherit';
    }

    public static function normalizeRouteMode(string $value): string
    {
        return ChannelRoutePolicy::normalizeChannelRouteMode($value);
    }

    public static function normalizeRouteSeparator(string $value): string
    {
        return ChannelRoutePolicy::normalizeChannelSeparator($value);
    }

    public static function normalizeNullablePath(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $path = trim((string) ($value ?? ''));
        return $path === '' ? null : $path;
    }

    public static function normalizeFeedEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (!is_scalar($value) && $value !== null) {
            return false;
        }

        $normalized = strtolower(trim((string) ($value ?? '')));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public static function normalizeChannelId(mixed $value): ?int
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
        return $id >= self::ROOT_CHANNEL_ID ? $id : null;
    }

    /**
     * @return array<int, int|string>
     */
    public static function normalizeTaxonomySetSelection(mixed $value, bool $defaultAll = true): array
    {
        return TaxonomySetRecordPolicy::normalizeSelection($value, $defaultAll);
    }
}
