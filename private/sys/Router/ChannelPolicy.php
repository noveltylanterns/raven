<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/ChannelPolicy.php
 * Route-mode normalization, separator policy, and config-backed effective-mode resolution.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;

/**
 * Static routing policy helpers: route-mode normalization, separator resolution, and config-backed effective-mode resolution.
 *
 * The Config-taking methods (globalPageRouteMode, effectiveChannelRouteMode, resolveChannelSeparator) are the
 * canonical entry point for any code that needs to resolve URL policy without loading the full ChannelParser stack.
 * Shared by ChannelParser, ChannelRead, PagePolicy, and panel/public controllers.
 */
final class ChannelPolicy
{
    /**
     * Normalizes a per-channel route-mode value, preserving 'inherit' as a valid option.
     *
     * @param string $value Raw route-mode string from a channel record or config.
     * @return string       'inherit', or one of the concrete route mode strings from normalizeRouteMode().
     */
    public static function normalizeChannelRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        if ($mode === '' || $mode === 'inherit') {
            return 'inherit';
        }

        return self::normalizeRouteMode($mode);
    }

    /**
     * Normalizes a concrete route-mode value (no 'inherit') to one of the accepted strings.
     *
     * @param string $value Raw route-mode string.
     * @return string       One of 'slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'; defaults to 'slug'.
     */
    public static function normalizeRouteMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'], true)
            ? $mode
            : 'slug';
    }

    /**
     * Returns whether a given route mode produces page-id URL segments rather than slugs.
     *
     * @param string $routeMode Normalized route mode string.
     * @return bool             True when the mode uses the numeric page id in the URL.
     */
    public static function usesPageId(string $routeMode): bool
    {
        $mode = self::normalizeRouteMode($routeMode);
        return in_array($mode, ['id', 'date_id', 'month_id'], true);
    }

    /**
     * Normalizes a per-channel word-separator value, preserving 'inherit'.
     *
     * @param string $value Raw separator string from a channel record.
     * @return string       'inherit', '-', or '_'.
     */
    public static function normalizeChannelSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['inherit', '-', '_'], true)
            ? $separator
            : 'inherit';
    }

    /**
     * Normalizes a global word-separator config value to '-' or '_'.
     *
     * @param string $value Raw separator string from site config.
     * @return string       '-' or '_'; defaults to '-'.
     */
    public static function normalizeGlobalSeparator(string $value): string
    {
        $separator = trim($value);
        return in_array($separator, ['-', '_'], true)
            ? $separator
            : '-';
    }

    /**
     * Resolves the effective word separator by merging per-channel and global values.
     *
     * Falls back to the global separator when the channel value is 'inherit'.
     *
     * @param string $channelValue Per-channel separator (may be 'inherit').
     * @param string $globalValue  Site-wide separator fallback.
     * @return string              Resolved separator: '-' or '_'.
     */
    public static function resolveSeparator(string $channelValue, string $globalValue): string
    {
        $normalizedChannel = self::normalizeChannelSeparator($channelValue);
        if ($normalizedChannel !== 'inherit') {
            return $normalizedChannel;
        }

        return self::normalizeGlobalSeparator($globalValue);
    }

    /**
     * Returns the site-wide global page route mode from config.
     *
     * Reads the `content.mode` config key and constrains the value to the two
     * site-level modes (`slug`, `id`). Per-channel modes use the full normalizer.
     *
     * @param Config $config Runtime site configuration.
     * @return string        'slug' or 'id'; defaults to 'slug'.
     */
    public static function globalPageRouteMode(Config $config): string
    {
        $mode = strtolower(trim((string) $config->get('content.mode', 'slug')));
        return in_array($mode, ['slug', 'id'], true) ? $mode : 'slug';
    }

    /**
     * Returns the effective page route mode for one channel, resolving `inherit` against the global default.
     *
     * When the channel record carries `inherit`, the site-wide `content.mode` is used.
     * Otherwise the channel value is run through the full route-mode normalizer.
     *
     * @param Config $config       Runtime site configuration (for global fallback).
     * @param string $channelValue Per-channel route-mode value from the channel record.
     * @return string              Concrete route-mode key used for URL lookups and path generation.
     */
    public static function effectiveChannelRouteMode(Config $config, string $channelValue): string
    {
        $mode = self::normalizeChannelRouteMode($channelValue);
        return $mode === 'inherit' ? self::globalPageRouteMode($config) : self::normalizeRouteMode($mode);
    }

    /**
     * Resolves the effective word separator for one channel's page routes against site config.
     *
     * Reads the global `content.separator` fallback directly from config, then delegates
     * to resolveSeparator() for the inherit-aware merge.
     *
     * @param Config $config       Runtime site configuration (for global separator fallback).
     * @param string $channelValue Per-channel separator value from the channel record.
     * @return string              Resolved separator: '-' or '_'.
     */
    public static function resolveChannelSeparator(Config $config, string $channelValue): string
    {
        return self::resolveSeparator(
            $channelValue,
            (string) $config->get('content.separator', '-')
        );
    }
}
