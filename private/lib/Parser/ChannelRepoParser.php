<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ChannelRepoParser.php
 * Low-level channel constants and static normalization primitives shared by repositories and parsers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;


/**
 * Stateless channel normalization primitives and context-hydration helpers.
 *
 * This class exists as a shared constant and static-method primitive layer so that
 * `sys/Repository/` classes can import channel normalization logic without mixing in
 * the filesystem-backed channel-store reads that live on `Repository\ChannelRead`.
 *
 * Do not add instance methods or filesystem I/O here; those belong on the repository seam.
 */
final class ChannelRepoParser
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
     * Normalizes a nullable path scalar to a trimmed string or null.
     *
     * @param mixed $value Raw value; must be scalar or null.
     * @return string|null Trimmed path string, or null when the value is empty or non-scalar.
     */
    public static function normalizeNullablePath(mixed $value): ?string
    {
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

}
