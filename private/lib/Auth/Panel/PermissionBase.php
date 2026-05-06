<?php

/**
 * RAVEN CMS
 * ~/private/lib/Auth/Panel/PermissionBase.php
 * Canonical panel permission bitmask constants, route maps, and capability helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Auth\Panel;

/**
 * Canonical panel permission constants and mask helpers.
 *
 * This class is the long-term home for panel permission rules and stock route maps.
 */
final class PermissionBase
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
     * @var array<string, array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}>|null
     */
    private static ?array $stockPanelRoutePermissionsCache = null;

    /** @var array<int, int>|null */
    private static ?array $allStockPanelBitsCache = null;

    /**
     * Returns required stock groups.
     *
     * @return array<int, array{name: string, slug: string, permissions: int, is_stock: int}>
     */
    public static function stockGroups(): array
    {
        $allStockPanelBitsMask = self::maskFromBits(self::allStockPanelBits());

        return [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'permissions' => self::PANEL_LOGIN
                    | self::VIEW_PUBLIC_SITE
                    | self::VIEW_PRIVATE_SITE
                    | self::VIEW_DISABLED_SITE
                    | self::MANAGE_CONTENT
                    | self::MANAGE_TAXONOMY
                    | self::MANAGE_USERS
                    | self::MANAGE_GROUPS
                    | self::MANAGE_CONFIGURATION
                    | $allStockPanelBitsMask,
                'is_stock' => 1,
            ],
            [
                'name' => 'Guest',
                'slug' => 'guest',
                'permissions' => self::VIEW_PUBLIC_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'Validating',
                'slug' => 'validating',
                'permissions' => self::VIEW_PUBLIC_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'User',
                'slug' => 'user',
                'permissions' => self::VIEW_PUBLIC_SITE | self::VIEW_PRIVATE_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'Banned',
                'slug' => 'banned',
                'permissions' => 0,
                'is_stock' => 1,
            ],
        ];
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
     * @param int $mask Combined permission bitmask for the user.
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
        if (is_array(self::$stockPanelRoutePermissionsCache)) {
            return self::$stockPanelRoutePermissionsCache;
        }

        self::$stockPanelRoutePermissionsCache = [
            'page' => [
                'label' => 'Pages',
                'view' => self::PAGES_VIEW,
                'create' => self::PAGES_CREATE,
                'edit' => self::PAGES_EDIT,
                'delete' => self::PAGES_DELETE,
            ],
            'channel' => [
                'label' => 'Channels',
                'view' => self::CHANNELS_VIEW,
                'create' => self::CHANNELS_CREATE,
                'edit' => self::CHANNELS_EDIT,
                'delete' => self::CHANNELS_DELETE,
            ],
            'category' => [
                'label' => 'Categories',
                'view' => self::CATEGORIES_VIEW,
                'create' => self::CATEGORIES_CREATE,
                'edit' => self::CATEGORIES_EDIT,
                'delete' => self::CATEGORIES_DELETE,
            ],
            'tag' => [
                'label' => 'Tags',
                'view' => self::TAGS_VIEW,
                'create' => self::TAGS_CREATE,
                'edit' => self::TAGS_EDIT,
                'delete' => self::TAGS_DELETE,
            ],
            'redirect' => [
                'label' => 'Redirects',
                'view' => self::REDIRECTS_VIEW,
                'create' => self::REDIRECTS_CREATE,
                'edit' => self::REDIRECTS_EDIT,
                'delete' => self::REDIRECTS_DELETE,
            ],
            'user' => [
                'label' => 'Users',
                'view' => self::USERS_VIEW,
                'create' => self::USERS_CREATE,
                'edit' => self::USERS_EDIT,
                'delete' => self::USERS_DELETE,
            ],
            'group' => [
                'label' => 'Groups',
                'view' => self::GROUPS_VIEW,
                'create' => self::GROUPS_CREATE,
                'edit' => self::GROUPS_EDIT,
                'delete' => self::GROUPS_DELETE,
            ],
            'routing' => [
                'label' => 'Routing',
                'view' => self::ROUTING_VIEW,
                'create' => self::ROUTING_CREATE,
                'edit' => self::ROUTING_EDIT,
                'delete' => self::ROUTING_DELETE,
            ],
            'themes' => [
                'label' => 'Themes',
                'view' => self::THEMES_VIEW,
                'create' => self::THEMES_CREATE,
                'edit' => self::THEMES_EDIT,
                'uninstall' => self::THEMES_UNINSTALL,
            ],
            'extensions' => [
                'label' => 'Extensions',
                'view' => self::EXTENSIONS_VIEW,
                'create' => self::EXTENSIONS_CREATE,
                'edit' => self::EXTENSIONS_EDIT,
                'uninstall' => self::EXTENSIONS_UNINSTALL,
            ],
            'configuration' => [
                'label' => 'Configuration',
                'view' => self::CONFIGURATION_VIEW,
                'create' => self::CONFIGURATION_CREATE,
                'edit' => self::CONFIGURATION_EDIT,
                'delete' => self::CONFIGURATION_DELETE,
            ],
            'logs' => [
                'label' => 'Event Log',
                // View and clear (delete) reuse Configuration bits because logs are system-level diagnostics.
                'view' => self::CONFIGURATION_VIEW,
                'delete' => self::CONFIGURATION_DELETE,
            ],
            'update' => [
                'label' => 'Update Raven',
                'view' => self::MANAGE_CONFIGURATION,
            ],
        ];

        return self::$stockPanelRoutePermissionsCache;
    }

    /**
     * Returns the stock permission row for one route key, or null when not found.
     *
     * @param string $routeKey Lowercase panel route key (e.g. 'page', 'user').
     * @return array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}|null
     */
    public static function stockPanelRoutePermission(string $routeKey): ?array
    {
        $definitions = self::stockPanelRoutePermissions();
        $normalized = strtolower(trim($routeKey));
        return $definitions[$normalized] ?? null;
    }

    /**
     * Returns all individual stock panel route-level permission bits.
     *
     * @return array<int, int> Flat list of every stock bit value.
     */
    public static function allStockPanelBits(): array
    {
        if (is_array(self::$allStockPanelBitsCache)) {
            return self::$allStockPanelBitsCache;
        }

        $bits = [];
        foreach (self::stockPanelRoutePermissions() as $permissionRow) {
            foreach (['view', 'create', 'edit', 'delete', 'uninstall'] as $action) {
                $bit = (int) ($permissionRow[$action] ?? 0);
                if ($bit > 0) {
                    $bits[] = $bit;
                }
            }
        }

        self::$allStockPanelBitsCache = $bits;
        return self::$allStockPanelBitsCache;
    }

    /**
     * Returns content-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function contentPanelBits(): array
    {
        return self::routeBits('page');
    }

    /**
     * Returns taxonomy-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function taxonomyPanelBits(): array
    {
        return self::routesBits(['channel', 'category', 'tag', 'redirect', 'routing']);
    }

    /**
     * Returns users-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function usersPanelBits(): array
    {
        return self::routeBits('user');
    }

    /**
     * Returns groups-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function groupsPanelBits(): array
    {
        return self::routeBits('group');
    }

    /**
     * Returns system-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function systemPanelBits(): array
    {
        return array_merge(
            [self::MANAGE_CONFIGURATION],
            self::routesBits(['configuration', 'themes', 'extensions'])
        );
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

    /**
     * Strips route-level panel bits from a mask when PANEL_LOGIN is not present.
     *
     * Prevents masks that grant route access (e.g. PAGES_EDIT) without granting panel
     * login itself, which would produce an incoherent permission set.
     *
     * @param int $mask Raw permission mask to normalize.
     * @return int Corrected mask with invalid bit combinations removed.
     */
    public static function normalizeMaskForPanelAccess(int $mask): int
    {
        if (($mask & self::PANEL_LOGIN) !== self::PANEL_LOGIN) {
            $mask &= ~self::allStockPanelBitsMask();
            $mask &= ~self::VIEW_DISABLED_SITE;
        }

        return $mask;
    }

    /**
     * Returns the permission bits for a single route key.
     *
     * @param string $route Lowercase panel route key.
     * @return array<int, int>
     */
    private static function routeBits(string $route): array
    {
        $row = self::stockPanelRoutePermission($route);
        if ($row === null) {
            return [];
        }

        $bits = [];
        foreach (['view', 'create', 'edit', 'delete', 'uninstall'] as $action) {
            $bit = (int) ($row[$action] ?? 0);
            if ($bit > 0) {
                $bits[] = $bit;
            }
        }

        return $bits;
    }

    /**
     * Returns the merged permission bits for a list of route keys.
     *
     * @param array<int, string> $routes Lowercase panel route keys.
     * @return array<int, int>
     */
    private static function routesBits(array $routes): array
    {
        $bits = [];
        foreach ($routes as $route) {
            $bits = array_merge($bits, self::routeBits($route));
        }

        return $bits;
    }
}
