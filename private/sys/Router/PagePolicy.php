<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/PagePolicy.php
 * Static page URL segment resolution and route-segment building helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Lib\Security\InputSanitizer;

/**
 * Static helpers for resolving and building page URL segments.
 *
 * All methods are stateless; callers pass the sanitizer and route-policy values
 * directly so this class can be used without constructing a PageDataParser instance.
 * Route-mode and separator policy is delegated to ChannelPolicy.
 */
final class PagePolicy
{
    /**
     * Normalizes a URL path segment for a slug-based page lookup.
     *
     * Converts underscores to hyphens first when the channel uses underscore separators,
     * then runs the result through the input sanitizer's slug rules.
     *
     * @param InputSanitizer $input         Shared input sanitizer.
     * @param string         $segment       Raw URL path segment.
     * @param string         $wordSeparator Per-channel or global separator value.
     * @return string|null                  Normalized slug, or null when the segment is invalid.
     */
    public static function normalizeSlugForLookup(InputSanitizer $input, string $segment, string $wordSeparator): ?string
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $segment) !== 1) {
            return null;
        }

        // When the separator is underscore, convert underscores to hyphens before sanitizing.
        $resolvedSeparator = ChannelPolicy::resolveSeparator($wordSeparator, '-');
        if ($resolvedSeparator === '_') {
            $segment = str_replace('_', '-', $segment);
        }

        return $input->slug($segment);
    }

    /**
     * Parses a date-prefixed page URL segment (e.g. '2024-06-01-my-page') into date and slug parts.
     *
     * @param InputSanitizer $input         Shared input sanitizer used for slug normalization.
     * @param string         $segment       Raw URL path segment.
     * @param string         $wordSeparator Per-channel or global separator value.
     * @return array{date: string, slug: string}|null Parsed parts, or null when the segment does not match.
     */
    public static function parseDateSlugSegment(InputSanitizer $input, string $segment, string $wordSeparator): ?array
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $segment, $matches) !== 1) {
            return null;
        }

        $slug = self::normalizeSlugForLookup($input, (string) ($matches[2] ?? ''), $wordSeparator);
        if ($slug === null || $slug === '') {
            return null;
        }

        return [
            'date' => (string) ($matches[1] ?? ''),
            'slug' => $slug,
        ];
    }

    /**
     * Normalizes a URL path segment for a numeric page-id lookup.
     *
     * @param string $segment Raw URL path segment.
     * @return int|null       Parsed positive page id, or null when the segment is not a valid id.
     */
    public static function normalizePageIdForLookup(string $segment): ?int
    {
        $segment = trim($segment);
        if ($segment === '' || preg_match('/^[1-9][0-9]*$/', $segment) !== 1) {
            return null;
        }

        $id = (int) $segment;
        return $id > 0 ? $id : null;
    }

    /**
     * Resolves a URL path segment to a typed lookup target (slug or id) based on the route mode.
     *
     * @param InputSanitizer $input         Shared input sanitizer for slug normalization.
     * @param string         $segment       Raw URL path segment.
     * @param string         $routeMode     Effective route mode for the channel.
     * @param string         $wordSeparator Per-channel or global separator value.
     * @return array{type: 'slug', slug: string}|array{type: 'id', id: int}|null Lookup target, or null on failure.
     */
    public static function resolveLookupTarget(
        InputSanitizer $input,
        string $segment,
        string $routeMode,
        string $wordSeparator
    ): ?array {
        $mode = ChannelPolicy::normalizeRouteMode($routeMode);
        $lookupValue = self::extractLookupValue($segment, $mode);
        if ($lookupValue === null || $lookupValue === '') {
            return null;
        }

        if (ChannelPolicy::usesPageId($mode)) {
            $id = self::normalizePageIdForLookup($lookupValue);
            return $id === null ? null : ['type' => 'id', 'id' => $id];
        }

        $slug = self::normalizeSlugForLookup($input, $lookupValue, $wordSeparator);
        return ($slug === null || $slug === '')
            ? null
            : ['type' => 'slug', 'slug' => $slug];
    }

    /**
     * Builds a URL route segment for a page given its route mode and separator settings.
     *
     * @param InputSanitizer $input                Shared input sanitizer.
     * @param string         $slug                 Page slug.
     * @param int            $pageId               Page id (used when mode is id-based).
     * @param string         $createdAt            Page created-at timestamp string.
     * @param string         $routeMode            Effective route mode for the channel.
     * @param string         $channelWordSeparator Per-channel separator value.
     * @param string         $globalWordSeparator  Site-wide separator fallback.
     * @return string                              Assembled route segment, or '' when inputs are invalid.
     */
    public static function buildRouteSegment(
        InputSanitizer $input,
        string $slug,
        int $pageId,
        string $createdAt,
        string $routeMode,
        string $channelWordSeparator,
        string $globalWordSeparator
    ): string {
        $mode = ChannelPolicy::normalizeRouteMode($routeMode);
        if (ChannelPolicy::usesPageId($mode)) {
            if ($pageId <= 0) {
                return '';
            }

            $routeValue = (string) $pageId;
        } else {
            $normalizedSlug = $input->slug($slug);
            if ($normalizedSlug === null || $normalizedSlug === '') {
                return '';
            }

            $resolvedSeparator = ChannelPolicy::resolveSeparator($channelWordSeparator, $globalWordSeparator);
            $routeValue = $resolvedSeparator === '_'
                ? str_replace('-', '_', $normalizedSlug)
                : $normalizedSlug;
        }

        $prefix = self::datePrefix($createdAt, $mode);
        if ($prefix === '') {
            return $routeValue;
        }

        return $prefix . '-' . $routeValue;
    }

    /**
     * Extracts the date prefix component for a page route segment given a timestamp and route mode.
     *
     * @param string $createdAt Page created-at timestamp string; may be empty (falls back to current time).
     * @param string $routeMode Route mode controlling prefix format ('date_slug' → 'Y-m-d', 'month_slug' → 'Y-m').
     * @return string           Date prefix string (e.g. '2024-06-01'), or '' for non-date modes.
     */
    public static function datePrefix(string $createdAt, string $routeMode = 'date_slug'): string
    {
        $mode = ChannelPolicy::normalizeRouteMode($routeMode);
        $format = match ($mode) {
            'date_slug', 'date_id' => 'Y-m-d',
            'month_slug', 'month_id' => 'Y-m',
            default => '',
        };
        if ($format === '') {
            return '';
        }

        $createdAt = trim($createdAt);
        if ($createdAt !== '') {
            if ($format === 'Y-m-d' && preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt, $matches) === 1) {
                return (string) ($matches[0] ?? gmdate('Y-m-d'));
            }
            if ($format === 'Y-m' && preg_match('/^\d{4}-\d{2}/', $createdAt, $matches) === 1) {
                return (string) ($matches[0] ?? gmdate('Y-m'));
            }
        }

        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
        }

        return gmdate($format, $timestamp);
    }

    /**
     * Extracts the lookup-relevant value from a URL segment by stripping the date prefix when present.
     *
     * @param string $segment   Normalized lowercase URL segment.
     * @param string $routeMode Normalized route mode string.
     * @return string|null      Lookup value, or null when the segment does not match the expected format.
     */
    private static function extractLookupValue(string $segment, string $routeMode): ?string
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return null;
        }

        return match (ChannelPolicy::normalizeRouteMode($routeMode)) {
            'date_slug', 'date_id' => self::extractPrefixedValue($segment, '/^\d{4}-\d{2}-\d{2}-(.+)$/'),
            'month_slug', 'month_id' => self::extractPrefixedValue($segment, '/^\d{4}-\d{2}-(.+)$/'),
            default => $segment,
        };
    }

    /**
     * Extracts the captured group from a prefixed URL segment using a regex pattern.
     *
     * @param string $segment Lowercase URL segment.
     * @param string $pattern Regex with one capture group for the post-prefix value.
     * @return string|null    Captured value, or null when the pattern does not match.
     */
    private static function extractPrefixedValue(string $segment, string $pattern): ?string
    {
        if (preg_match($pattern, $segment, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));
        return $value === '' ? null : $value;
    }
}
