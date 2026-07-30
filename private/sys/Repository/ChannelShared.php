<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelShared.php
 * Shared repository primitives for channel constants and normalization helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

/**
 * Stateless channel normalization primitives shared across ChannelRead, ChannelWrite, and their callers.
 *
 * This class exists as a shared constant and static-method primitive layer so that
 * repository classes can import channel normalization logic without mixing in the
 * filesystem-backed channel-store reads that live on ChannelRead.
 *
 * Do not add instance methods or filesystem I/O here; those belong on the repository seam.
 */
final class ChannelShared
{
    /** Id assigned to the implicit root (all-pages) channel. */
    public const ROOT_CHANNEL_ID = 0;

    /** Slug reserved for the root channel. */
    public const ROOT_CHANNEL_SLUG = 'root';

    /** Display name used internally for the root channel. */
    public const ROOT_CHANNEL_NAME = '<root>';

    // -------------------------------------------------------------------------
    // Slug and id validation
    // -------------------------------------------------------------------------

    /**
     * Returns whether a slug string meets channel slug format requirements.
     *
     * @param string $slug Slug to test.
     * @return bool        True when the slug is non-empty and contains only lowercase alphanumerics and hyphens.
     */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim($slug))) === 1;
    }

    /**
     * Returns whether the given id is the root channel id.
     *
     * @param int $id Channel id to test.
     * @return bool   True when $id equals ROOT_CHANNEL_ID.
     */
    public static function isRootChannelId(int $id): bool
    {
        return $id === self::ROOT_CHANNEL_ID;
    }

    /**
     * Returns whether the given slug is the root channel slug.
     *
     * @param string $slug Slug to test.
     * @return bool        True when the normalized slug equals ROOT_CHANNEL_SLUG.
     */
    public static function isRootChannelSlug(string $slug): bool
    {
        return strtolower(trim($slug)) === self::ROOT_CHANNEL_SLUG;
    }

    // -------------------------------------------------------------------------
    // Field normalizers
    // -------------------------------------------------------------------------

    /**
     * Normalizes an editor-override value to one of the accepted enum strings.
     *
     * @param string $value Raw override value.
     * @return string       One of 'inherit', 'tinymce', 'plaintext', 'autobr', or 'markdown'; defaults to 'inherit'.
     */
    public static function normalizeEditorOverride(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $normalized
            : 'inherit';
    }

    /**
     * Normalizes a channel theme override to the inherit sentinel or a safe theme slug.
     *
     * Installed-theme validation belongs to the panel/theme catalog boundary; repository
     * normalization only guarantees that persisted values are safe to use in path lookups.
     *
     * @param string $value Raw channel theme override value.
     * @return string `inherit` or a filesystem-safe public-theme slug.
     */
    public static function normalizeThemeOverride(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'inherit') {
            return 'inherit';
        }

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $normalized) === 1
            ? $normalized
            : 'inherit';
    }

    /**
     * Normalizes a channel parent id to a non-negative integer, defaulting to root.
     *
     * @param mixed $value Raw parent id value from a form or channel record.
     * @return int Valid non-negative parent id, or ROOT_CHANNEL_ID when invalid.
     */
    public static function normalizeParentId(mixed $value): int
    {
        if (is_int($value)) {
            return $value >= self::ROOT_CHANNEL_ID ? $value : self::ROOT_CHANNEL_ID;
        }

        if (!is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) {
            return self::ROOT_CHANNEL_ID;
        }

        $normalized = filter_var(trim($value), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => self::ROOT_CHANNEL_ID],
        ]);

        return is_int($normalized) ? $normalized : self::ROOT_CHANNEL_ID;
    }

    /**
     * Normalizes a nullable path scalar to a trimmed string or null.
     *
     * @param mixed $value Raw value; must be scalar or null.
     * @return string|null Trimmed path string, or null when the value is empty or non-scalar.
     */
    public static function normalizeNullablePath(mixed $value): ?string
    {
        // Reject non-scalar payloads to avoid persisting arrays/objects as path strings.
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $path = trim((string) ($value ?? ''));
        return $path === '' ? null : $path;
    }

    /**
     * Normalizes a raw feed-enabled value to a boolean.
     *
     * @param mixed $value Raw value; accepts bool, int, float, or truthy string.
     * @return bool        True when the value represents an enabled state.
     */
    public static function normalizeFeedEnabled(mixed $value): bool
    {
        // Preserve booleans exactly as provided.
        if (is_bool($value)) {
            return $value;
        }

        // Numeric payloads use 1 as enabled and all other values as disabled.
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        // Non-scalar payloads are invalid for boolean normalization and default to false.
        if (!is_scalar($value) && $value !== null) {
            return false;
        }

        $normalized = strtolower(trim((string) ($value ?? '')));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
