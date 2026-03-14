<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared channel page-route mode/separator policy helpers.
 */
final class ChannelRoutePolicy
{
    public static function normalizeRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['slug', 'date_slug'], true)
            ? $mode
            : 'slug';
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

    public static function buildRouteSegment(
        InputSanitizer $input,
        string $slug,
        string $publishedAt,
        string $routeMode,
        string $channelWordSeparator,
        string $globalWordSeparator
    ): string {
        $normalizedSlug = $input->slug($slug);
        if ($normalizedSlug === null || $normalizedSlug === '') {
            return '';
        }

        $resolvedSeparator = self::resolveSeparator($channelWordSeparator, $globalWordSeparator);
        $routeSlug = $resolvedSeparator === '_'
            ? str_replace('-', '_', $normalizedSlug)
            : $normalizedSlug;

        $mode = self::normalizeRouteMode($routeMode);
        if ($mode !== 'date_slug') {
            return $routeSlug;
        }

        return self::datePrefix($publishedAt) . '-' . $routeSlug;
    }

    public static function datePrefix(string $publishedAt): string
    {
        $publishedAt = trim($publishedAt);
        if ($publishedAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $publishedAt, $matches) === 1) {
            return (string) ($matches[0] ?? gmdate('Y-m-d'));
        }

        $timestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = time();
        }

        return gmdate('Y-m-d', $timestamp);
    }
}
