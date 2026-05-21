<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Public/PermissionBase.php
 * Canonical public-route permission bitmask helpers for site-visibility access checks.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Public;

/**
 * Canonical public-route permission helpers.
 */
final class PermissionBase
{
    /** Allows access to public-site mode frontend routes/content. */
    public const VIEW_PUBLIC_SITE = 128;

    /** Allows access to private-site mode frontend routes/content. */
    public const VIEW_PRIVATE_SITE = 256;

    /** Allows authenticated dashboard users to view frontend while site mode is disabled. */
    public const VIEW_DISABLED_SITE = 512;

    /**
     * Checks public-site-view capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when VIEW_PUBLIC_SITE bit is set.
     */
    public static function canViewPublicSite(int $mask): bool
    {
        return (bool) ($mask & self::VIEW_PUBLIC_SITE);
    }

    /**
     * Checks private-site-view capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when VIEW_PRIVATE_SITE bit is set.
     */
    public static function canViewPrivateSite(int $mask): bool
    {
        return (bool) ($mask & self::VIEW_PRIVATE_SITE);
    }

    /**
     * Checks disabled-site-view capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when VIEW_DISABLED_SITE bit is set.
     */
    public static function canViewDisabledSite(int $mask): bool
    {
        return (bool) ($mask & self::VIEW_DISABLED_SITE);
    }

    /**
     * Returns the public-route permission bits.
     *
     * @return array<int, int> Bits used by public-route site visibility checks.
     */
    public static function siteVisibilityBits(): array
    {
        return [
            self::VIEW_PUBLIC_SITE,
            self::VIEW_PRIVATE_SITE,
            self::VIEW_DISABLED_SITE,
        ];
    }
}
