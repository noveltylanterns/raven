<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Auth/PanelAccess.php
 * Authentication and authorization core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Auth;

require_once dirname(__DIR__, 3) . '/lib/Auth/PanelAccessCatalog.php';

use Raven\Lib\Auth\PanelAccessCatalog;

/**
 * Permission bitmask helpers and stock group definitions.
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
    public const THEMES_DELETE = 35184372088832;

    /** Extensions route permissions (`/extensions*`). */
    public const EXTENSIONS_VIEW = 70368744177664;
    public const EXTENSIONS_CREATE = 140737488355328;
    public const EXTENSIONS_EDIT = 281474976710656;
    public const EXTENSIONS_DELETE = 562949953421312;

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
     * @return array<int, array{name: string, slug: string, permission_mask: int, is_stock: int}>
     */
    public static function stockGroups(): array
    {
        return PanelAccessCatalog::stockGroups();
    }

    /**
     * Checks dashboard-access permission from combined mask.
     */
    public static function canLoginPanel(int $mask): bool
    {
        return (bool) ($mask & self::PANEL_LOGIN);
    }

    /**
     * Checks user-management capability.
     */
    public static function canManageUsers(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_USERS)
            || self::hasAnyPanelPermissionBit($mask, self::usersPanelBits());
    }

    /**
     * Checks group-management capability.
     */
    public static function canManageGroups(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_GROUPS)
            || self::hasAnyPanelPermissionBit($mask, self::groupsPanelBits());
    }

    /**
     * Checks content-management capability.
     */
    public static function canManageContent(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_CONTENT)
            || self::hasAnyPanelPermissionBit($mask, self::contentPanelBits());
    }

    /**
     * Checks system-configuration capability.
     */
    public static function canManageConfiguration(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_CONFIGURATION)
            || self::hasAnyPanelPermissionBit($mask, self::systemPanelBits());
    }

    /**
     * Checks taxonomy-management capability.
     */
    public static function canManageTaxonomy(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_TAXONOMY)
            || self::hasAnyPanelPermissionBit($mask, self::taxonomyPanelBits());
    }

    /**
     * Checks public-site-view capability.
     */
    public static function canViewPublicSite(int $mask): bool
    {
        return (bool) ($mask & self::VIEW_PUBLIC_SITE);
    }

    /**
     * Checks private-site-view capability.
     */
    public static function canViewPrivateSite(int $mask): bool
    {
        return (bool) ($mask & self::VIEW_PRIVATE_SITE);
    }

    /**
     * Checks disabled-site-view capability.
     */
    public static function canViewDisabledSite(int $mask): bool
    {
        return (bool) ($mask & self::VIEW_DISABLED_SITE);
    }

    /**
     * Returns true when one exact panel bit is enabled.
     */
    public static function hasPanelPermissionBit(int $mask, int $bit): bool
    {
        if ($bit <= 0) {
            return false;
        }

        return ($mask & $bit) === $bit;
    }

    /**
     * Returns true when any bit in list is enabled.
     *
     * @param array<int, int> $bits
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
     * Returns one normalized panel route permission map.
     *
     * @return array<string, array{label: string, view: int, create: int, edit: int, delete: int}>
     */
    public static function stockPanelRoutePermissions(): array
    {
        return PanelAccessCatalog::stockPanelRoutePermissions();
    }

    /**
     * Returns stock panel route permission row by route key.
     *
     * @return array{label: string, view: int, create: int, edit: int, delete: int}|null
     */
    public static function stockPanelRoutePermission(string $routeKey): ?array
    {
        return PanelAccessCatalog::stockPanelRoutePermission($routeKey);
    }

    /**
     * Returns all stock panel permission bits.
     *
     * @return array<int, int>
     */
    public static function allStockPanelBits(): array
    {
        return PanelAccessCatalog::allStockPanelBits();
    }

    /**
     * Returns content-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function contentPanelBits(): array
    {
        return PanelAccessCatalog::contentPanelBits();
    }

    /**
     * Returns taxonomy-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function taxonomyPanelBits(): array
    {
        return PanelAccessCatalog::taxonomyPanelBits();
    }

    /**
     * Returns users-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function usersPanelBits(): array
    {
        return PanelAccessCatalog::usersPanelBits();
    }

    /**
     * Returns groups-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function groupsPanelBits(): array
    {
        return PanelAccessCatalog::groupsPanelBits();
    }

    /**
     * Returns system-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function systemPanelBits(): array
    {
        return PanelAccessCatalog::systemPanelBits();
    }

    /**
     * Returns bitmask value for one list of bits.
     *
     * @param array<int, int> $bits
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
     * Returns mask containing all stock route-level panel bits.
     */
    public static function allStockPanelBitsMask(): int
    {
        return self::maskFromBits(self::allStockPanelBits());
    }
}
