<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared channel page-route mode/separator policy helpers.
 */
final class ChannelRoutePolicy
{
    public static function normalizeChannelRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        if ($mode === '' || $mode === 'inherit') {
            return 'inherit';
        }

        return self::normalizeRouteMode($mode);
    }

    public static function normalizeRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'], true)
            ? $mode
            : 'slug';
    }

    public static function usesPageId(string $routeMode): bool
    {
        $mode = self::normalizeRouteMode($routeMode);
        return in_array($mode, ['id', 'date_id', 'month_id'], true);
    }

    public static function normalizeChannelSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['inherit', '-', '_'], true)
            ? $separator
            : 'inherit';
    }

    public static function normalizeGlobalSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['-', '_'], true)
            ? $separator
            : '-';
    }

    public static function resolveSeparator(string $channelValue, string $globalValue): string
    {
        $normalizedChannel = self::normalizeChannelSeparator($channelValue);
        if ($normalizedChannel !== 'inherit') {
            return $normalizedChannel;
        }

        return self::normalizeGlobalSeparator($globalValue);
    }

    public static function normalizeSlugForLookup(InputSanitizer $input, string $segment, string $wordSeparator): ?string
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $segment) !== 1) {
            return null;
        }

        $resolvedSeparator = self::resolveSeparator($wordSeparator, '-');
        if ($resolvedSeparator === '_') {
            $segment = str_replace('_', '-', $segment);
        }

        return $input->slug($segment);
    }

    /**
     * @return array{date: string, slug: string}|null
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
     * @return array{type: 'slug', slug: string}|array{type: 'id', id: int}|null
     */
    public static function resolveLookupTarget(
        InputSanitizer $input,
        string $segment,
        string $routeMode,
        string $wordSeparator
    ): ?array {
        $mode = self::normalizeRouteMode($routeMode);
        $lookupValue = self::extractLookupValue($segment, $mode);
        if ($lookupValue === null || $lookupValue === '') {
            return null;
        }

        if (self::usesPageId($mode)) {
            $id = self::normalizePageIdForLookup($lookupValue);
            return $id === null ? null : ['type' => 'id', 'id' => $id];
        }

        $slug = self::normalizeSlugForLookup($input, $lookupValue, $wordSeparator);
        return ($slug === null || $slug === '')
            ? null
            : ['type' => 'slug', 'slug' => $slug];
    }

    public static function buildRouteSegment(
        InputSanitizer $input,
        string $slug,
        int $pageId,
        string $createdAt,
        string $routeMode,
        string $channelWordSeparator,
        string $globalWordSeparator
    ): string {
        $mode = self::normalizeRouteMode($routeMode);
        if (self::usesPageId($mode)) {
            if ($pageId <= 0) {
                return '';
            }

            $routeValue = (string) $pageId;
        } else {
            $normalizedSlug = $input->slug($slug);
            if ($normalizedSlug === null || $normalizedSlug === '') {
                return '';
            }

            $resolvedSeparator = self::resolveSeparator($channelWordSeparator, $globalWordSeparator);
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

    public static function datePrefix(string $createdAt, string $routeMode = 'date_slug'): string
    {
        $mode = self::normalizeRouteMode($routeMode);
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

    private static function extractLookupValue(string $segment, string $routeMode): ?string
    {
        $segment = strtolower(trim($segment));
        if ($segment === '') {
            return null;
        }

        return match (self::normalizeRouteMode($routeMode)) {
            'date_slug', 'date_id' => self::extractPrefixedValue($segment, '/^\d{4}-\d{2}-\d{2}-(.+)$/'),
            'month_slug', 'month_id' => self::extractPrefixedValue($segment, '/^\d{4}-\d{2}-(.+)$/'),
            default => $segment,
        };
    }

    private static function extractPrefixedValue(string $segment, string $pattern): ?string
    {
        if (preg_match($pattern, $segment, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));
        return $value === '' ? null : $value;
    }
}
