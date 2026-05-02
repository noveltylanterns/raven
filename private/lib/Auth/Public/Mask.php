<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Public/Mask.php
 * Canonical public-route permission bitmask helpers for site-visibility access checks.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Public;

use Raven\Lib\Auth\Panel\Mask as PanelMask;

/**
 * Canonical public-route permission helpers.
 */
final class Mask
{
    /** Allows access to public-site mode frontend routes/content. */
    public const VIEW_PUBLIC_SITE = PanelMask::VIEW_PUBLIC_SITE;

    /** Allows access to private-site mode frontend routes/content. */
    public const VIEW_PRIVATE_SITE = PanelMask::VIEW_PRIVATE_SITE;

    /** Allows authenticated dashboard users to view frontend while site mode is disabled. */
    public const VIEW_DISABLED_SITE = PanelMask::VIEW_DISABLED_SITE;

    /**
     * Checks public-site-view capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when VIEW_PUBLIC_SITE bit is set.
     */
    public static function canViewPublicSite(int $mask): bool
    {
        return PanelMask::canViewPublicSite($mask);
    }

    /**
     * Checks private-site-view capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when VIEW_PRIVATE_SITE bit is set.
     */
    public static function canViewPrivateSite(int $mask): bool
    {
        return PanelMask::canViewPrivateSite($mask);
    }

    /**
     * Checks disabled-site-view capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when VIEW_DISABLED_SITE bit is set.
     */
    public static function canViewDisabledSite(int $mask): bool
    {
        return PanelMask::canViewDisabledSite($mask);
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
