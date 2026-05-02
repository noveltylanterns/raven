<?php

/**
 * RAVEN CMS
 * ~/private/lib/Permission/PanelAccess.php
 * Panel permission bitmask constants and stock permission helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Permission;

require_once __DIR__ . '/AccessCatalog.php';

use Raven\Lib\Permission\AccessCatalog;

/**
 * Panel permission bitmask constants and stock capability check helpers.
 */
final class PanelAccess
{
    /** Allows access to public-site mode frontend routes/content. */
    public const VIEW_PUBLIC_SITE = 128;

    /** Allows access to private-site mode frontend routes/content. */
    public const VIEW_PRIVATE_SITE = 256;

    /** Allows authenticated dashboard users to view frontend while site mode is disabled. */
    public const VIEW_DISABLED_SITE = 512;

    /** Allows dashboard access. */
    public const PANEL_LOGIN = 1;

    /** Allows page/content operations (excluding taxonomy management). */
    public const MANAGE_CONTENT = 2;

    /** Allows taxonomy operations (channel/category/tag). */
    public const MANAGE_TAXONOMY = 64;

    /** Allows user management. */
    public const MANAGE_USERS = 4;

    /** Allows group management. */
    public const MANAGE_GROUPS = 8;

    /** Allows system configuration management (Configuration, Extensions, Updates). */
    public const MANAGE_CONFIGURATION = 16;

    /** Page route permissions (`/page*`). */
    public const PAGES_VIEW = 1024;
    public const PAGES_CREATE = 2048;
    public const PAGES_EDIT = 4096;
    public const PAGES_DELETE = 8192;

    /** Channel route permissions (`/channel*`). */
    public const CHANNELS_VIEW = 16384;
    public const CHANNELS_CREATE = 32768;
    public const CHANNELS_EDIT = 65536;
    public const CHANNELS_DELETE = 131072;

    /** Category route permissions (`/category*`). */
    public const CATEGORIES_VIEW = 262144;
    public const CATEGORIES_CREATE = 524288;
    public const CATEGORIES_EDIT = 1048576;
    public const CATEGORIES_DELETE = 2097152;

    /** Tag route permissions (`/tag*`). */
    public const TAGS_VIEW = 4194304;
    public const TAGS_CREATE = 8388608;
    public const TAGS_EDIT = 16777216;
    public const TAGS_DELETE = 33554432;

    /** Redirect route permissions (`/redirect*`). */
    public const REDIRECTS_VIEW = 67108864;
    public const REDIRECTS_CREATE = 134217728;
    public const REDIRECTS_EDIT = 268435456;
    public const REDIRECTS_DELETE = 536870912;

    /** User route permissions (`/user*`). */
    public const USERS_VIEW = 1073741824;
    public const USERS_CREATE = 2147483648;
    public const USERS_EDIT = 4294967296;
    public const USERS_DELETE = 8589934592;

    /** Group route permissions (`/group*`). */
    public const GROUPS_VIEW = 17179869184;
    public const GROUPS_CREATE = 34359738368;
    public const GROUPS_EDIT = 68719476736;
    public const GROUPS_DELETE = 137438953472;

    /** Routing route permissions (`/routing*`). */
    public const ROUTING_VIEW = 274877906944;
    public const ROUTING_CREATE = 549755813888;
    public const ROUTING_EDIT = 1099511627776;
    public const ROUTING_DELETE = 2199023255552;

    /** Themes route permissions (`/themes*`). */
    public const THEMES_VIEW = 4398046511104;
    public const THEMES_CREATE = 8796093022208;
    public const THEMES_EDIT = 17592186044416;
    public const THEMES_UNINSTALL = 35184372088832;

    /** Extensions route permissions (`/extensions*`). */
    public const EXTENSIONS_VIEW = 70368744177664;
    public const EXTENSIONS_CREATE = 140737488355328;
    public const EXTENSIONS_EDIT = 281474976710656;
    public const EXTENSIONS_UNINSTALL = 562949953421312;

    /** Configuration route permissions (`/configuration*`). */
    public const CONFIGURATION_VIEW = 1125899906842624;
    public const CONFIGURATION_CREATE = 2251799813685248;
    public const CONFIGURATION_EDIT = 4503599627370496;
    public const CONFIGURATION_DELETE = 9007199254740992;

    /** First dynamic extension-permission bit (2^54). */
    public const EXTENSION_PERMISSION_START = 18014398509481984;

    /**
     * Returns required stock groups.
     *
     * @return array<int, array{name: string, slug: string, permissions: int, is_stock: int}>
     */
    public static function stockGroups(): array
    {
        return AccessCatalog::stockGroups();
    }

    /**
     * Checks dashboard-access permission from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when the mask includes the PANEL_LOGIN bit.
     */
    public static function canLoginPanel(int $mask): bool
    {
        return (bool) ($mask & self::PANEL_LOGIN);
    }

    /**
     * Checks user-management capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when MANAGE_USERS or any users route bit is set.
     */
    public static function canManageUsers(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_USERS)
            || self::hasAnyPanelPermissionBit($mask, self::usersPanelBits());
    }

    /**
     * Checks group-management capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when MANAGE_GROUPS or any groups route bit is set.
     */
    public static function canManageGroups(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_GROUPS)
            || self::hasAnyPanelPermissionBit($mask, self::groupsPanelBits());
    }

    /**
     * Checks content-management capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when MANAGE_CONTENT or any content route bit is set.
     */
    public static function canManageContent(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_CONTENT)
            || self::hasAnyPanelPermissionBit($mask, self::contentPanelBits());
    }

    /**
     * Checks system-configuration capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when MANAGE_CONFIGURATION or any system route bit is set.
     */
    public static function canManageConfiguration(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_CONFIGURATION)
            || self::hasAnyPanelPermissionBit($mask, self::systemPanelBits());
    }

    /**
     * Checks taxonomy-management capability from combined mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @return bool True when MANAGE_TAXONOMY or any taxonomy route bit is set.
     */
    public static function canManageTaxonomy(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_TAXONOMY)
            || self::hasAnyPanelPermissionBit($mask, self::taxonomyPanelBits());
    }

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
     * Returns true when one exact panel permission bit is present in the mask.
     *
     * @param int $mask Combined permission bitmask for the user.
     * @param int $bit  The single bit to test.
     * @return bool True when $bit is fully set in $mask.
     */
    public static function hasPanelPermissionBit(int $mask, int $bit): bool
    {
        if ($bit <= 0) {
            return false;
        }

        return ($mask & $bit) === $bit;
    }

    /**
     * Returns true when any bit in the supplied list is present in the mask.
     *
     * @param int         $mask Combined permission bitmask for the user.
     * @param array<int, int> $bits List of bits to test (any match returns true).
     * @return bool True when at least one bit from $bits is set in $mask.
     */
    public static function hasAnyPanelPermissionBit(int $mask, array $bits): bool
    {
        foreach ($bits as $bit) {
            if (self::hasPanelPermissionBit($mask, (int) $bit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the full stock panel route permission map keyed by route key.
     *
     * @return array<string, array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}>
     */
    public static function stockPanelRoutePermissions(): array
    {
        return AccessCatalog::stockPanelRoutePermissions();
    }

    /**
     * Returns the stock permission row for one route key, or null when not found.
     *
     * @param string $routeKey Lowercase panel route key (e.g. 'page', 'user').
     * @return array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}|null
     */
    public static function stockPanelRoutePermission(string $routeKey): ?array
    {
        return AccessCatalog::stockPanelRoutePermission($routeKey);
    }

    /**
     * Returns all individual stock panel route-level permission bits.
     *
     * @return array<int, int> Flat list of every stock bit value.
     */
    public static function allStockPanelBits(): array
    {
        return AccessCatalog::allStockPanelBits();
    }

    /**
     * Returns content-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function contentPanelBits(): array
    {
        return AccessCatalog::contentPanelBits();
    }

    /**
     * Returns taxonomy-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function taxonomyPanelBits(): array
    {
        return AccessCatalog::taxonomyPanelBits();
    }

    /**
     * Returns users-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function usersPanelBits(): array
    {
        return AccessCatalog::usersPanelBits();
    }

    /**
     * Returns groups-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function groupsPanelBits(): array
    {
        return AccessCatalog::groupsPanelBits();
    }

    /**
     * Returns system-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function systemPanelBits(): array
    {
        return AccessCatalog::systemPanelBits();
    }

    /**
     * Combines a list of bits into a single bitmask value.
     *
     * @param array<int, int> $bits List of individual bit values to OR together.
     * @return int Combined bitmask.
     */
    public static function maskFromBits(array $bits): int
    {
        $mask = 0;
        foreach ($bits as $bit) {
            $mask |= (int) $bit;
        }

        return $mask;
    }

    /**
     * Returns a combined bitmask covering all stock route-level panel bits.
     *
     * @return int Bitmask of every stock route permission bit ORed together.
     */
    public static function allStockPanelBitsMask(): int
    {
        return self::maskFromBits(self::allStockPanelBits());
    }
}
