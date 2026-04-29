<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ChannelRepoParser.php
 * Low-level channel constants and static normalization primitives shared by repositories and parsers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use RuntimeException;

/**
 * Stateless channel normalization primitives and context-hydration helpers.
 *
 * This class exists as a shared constant and static-method primitive layer so that
 * `sys/Repository/` classes can import channel normalization logic without mixing in
 * the filesystem-backed channel-store reads that live on `Repository\ChannelRead`.
 *
 * Do not add instance methods or filesystem I/O here; those belong on the repository seam.
 */
class ChannelRepoParser
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
     * Normalizes a raw route-mode value through the routing policy.
     *
     * @param string $value Raw route-mode string.
     * @return string       Validated route mode string.
     */
    public static function normalizeRouteMode(string $value): string
    {
        return ChannelRouteParser::normalizeChannelRouteMode($value);
    }

    /**
     * Normalizes a raw route-separator value through the routing policy.
     *
     * @param string $value Raw separator string.
     * @return string       Validated separator string.
     */
    public static function normalizeRouteSeparator(string $value): string
    {
        return ChannelRouteParser::normalizeChannelSeparator($value);
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

    /**
     * Normalizes a raw channel id to an integer, or null when absent or invalid.
     *
     * @param mixed $value Raw value to parse; accepts any scalar or null.
     * @return int|null    Validated channel id (>= ROOT_CHANNEL_ID), or null.
     */
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
     * Normalizes a taxonomy-set selection value for storage on a channel record.
     *
     * Delegates to SetParser::normalizeSelection for consistent cross-entity normalization.
     *
     * @param mixed $value      Raw selection value.
     * @param bool  $defaultAll When true and the selection is empty, returns the all-sets sentinel.
     * @return array<int, int|string> Sorted set-id array.
     */
    public static function normalizeTaxonomySetSelection(mixed $value, bool $defaultAll = true): array
    {
        return SetParser::normalizeSelection($value, $defaultAll);
    }

    // -------------------------------------------------------------------------
    // Context-hydration helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a map of channel id → channel record from a flat list of channel rows.
     *
     * @param array<int, array<string, mixed>> $channelRecords Flat list of channel record arrays.
     * @return array<int, array<string, mixed>>               Map keyed by integer channel id.
     */
    public static function channelsByIdMap(array $channelRecords): array
    {
        $map = [];
        foreach ($channelRecords as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $id = (int) ($channel['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $map[$id] = $channel;
        }

        return $map;
    }

    /**
     * Applies channel slug and name context fields to a row array.
     *
     * @param array<string, mixed>      $row     Row array to augment.
     * @param array<string, mixed>|null $channel Channel record, or null when the row has no channel.
     * @return array<string, mixed>              Row with channel_slug and channel_name fields populated.
     */
    public static function applyBasicChannelContext(array $row, ?array $channel): array
    {
        $row['channel_slug'] = $channel !== null ? (string) ($channel['slug'] ?? '') : '';
        $row['channel_name'] = $channel !== null ? (string) ($channel['name'] ?? '') : '';

        return $row;
    }

    /**
     * Applies channel routing context fields to a page row array.
     *
     * Includes basic channel context plus effective route mode and separator values.
     *
     * @param array<string, mixed>      $row     Page row array to augment.
     * @param array<string, mixed>|null $channel Channel record, or null when the page has no channel.
     * @return array<string, mixed>              Row with channel context and routing fields populated.
     */
    public static function applyPageChannelContext(array $row, ?array $channel): array
    {
        $row = self::applyBasicChannelContext($row, $channel);
        $row['route_mode_effective'] = $channel !== null
            ? (string) ($channel['route_mode'] ?? 'inherit')
            : 'inherit';
        $row['route_separator_effective'] = $channel !== null
            ? (string) ($channel['route_separator'] ?? 'inherit')
            : 'inherit';

        return $row;
    }

    /**
     * Resolves a channel id from a slug string using a caller-provided lookup callback.
     *
     * @param string|null $slug             Raw slug to resolve; empty/null returns null without error.
     * @param callable    $idBySlugResolver Callback accepting a normalized slug string and returning int|null.
     * @param string      $missingMessage   Exception message when the slug resolves to no known channel.
     * @return int|null                     Resolved channel id, or null when slug is empty.
     * @throws RuntimeException When the slug is non-empty but resolves to no channel.
     */
    public static function resolveChannelIdBySlug(
        ?string $slug,
        callable $idBySlugResolver,
        string $missingMessage = 'Selected channel does not exist.'
    ): ?int {
        $normalized = strtolower(trim((string) ($slug ?? '')));
        if ($normalized === '') {
            return null;
        }

        $resolved = $idBySlugResolver($normalized);
        $id = $resolved !== null ? (int) $resolved : null;
        if ($id === null || $id < 1) {
            throw new RuntimeException($missingMessage);
        }

        return $id;
    }
}
