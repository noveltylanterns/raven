<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Auth/PanelAccess.php
 * Authentication and authorization core component.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Centralize auth and permission rules to keep checks consistent app-wide.

declare(strict_types=1);

namespace Raven\Core\Auth;

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

    /** Allows taxonomy operations (channels/categories/tags). */
    public const MANAGE_TAXONOMY = 64;

    /** Allows user management. */
    public const MANAGE_USERS = 4;

    /** Allows group management. */
    public const MANAGE_GROUPS = 8;

    /** Allows system configuration management (Configuration, Extensions, Updates). */
    public const MANAGE_CONFIGURATION = 16;

    /** Pages route permissions (`/pages*`). */
    public const PAGES_VIEW = 1024;
    public const PAGES_CREATE = 2048;
    public const PAGES_EDIT = 4096;
    public const PAGES_DELETE = 8192;

    /** Channels route permissions (`/channels*`). */
    public const CHANNELS_VIEW = 16384;
    public const CHANNELS_CREATE = 32768;
    public const CHANNELS_EDIT = 65536;
    public const CHANNELS_DELETE = 131072;

    /** Categories route permissions (`/categories*`). */
    public const CATEGORIES_VIEW = 262144;
    public const CATEGORIES_CREATE = 524288;
    public const CATEGORIES_EDIT = 1048576;
    public const CATEGORIES_DELETE = 2097152;

    /** Tags route permissions (`/tags*`). */
    public const TAGS_VIEW = 4194304;
    public const TAGS_CREATE = 8388608;
    public const TAGS_EDIT = 16777216;
    public const TAGS_DELETE = 33554432;

    /** Redirects route permissions (`/redirects*`). */
    public const REDIRECTS_VIEW = 67108864;
    public const REDIRECTS_CREATE = 134217728;
    public const REDIRECTS_EDIT = 268435456;
    public const REDIRECTS_DELETE = 536870912;

    /** Users route permissions (`/users*`). */
    public const USERS_VIEW = 1073741824;
    public const USERS_CREATE = 2147483648;
    public const USERS_EDIT = 4294967296;
    public const USERS_DELETE = 8589934592;

    /** Groups route permissions (`/groups*`). */
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
        $allStockPanelBitsMask = self::allStockPanelBitsMask();
        $editorPanelBitsMask = self::maskFromBits(self::contentPanelBits());
        $adminPanelBitsMask = self::maskFromBits(array_merge(
            self::contentPanelBits(),
            self::taxonomyPanelBits(),
            self::usersPanelBits()
        ));

        return [
            [
                'name' => 'Super Admin',
                'slug' => 'super',
                'permission_mask' => self::PANEL_LOGIN
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
                'name' => 'Admin',
                'slug' => 'admin',
                'permission_mask' => self::PANEL_LOGIN
                    | self::VIEW_PUBLIC_SITE
                    | self::VIEW_PRIVATE_SITE
                    | self::VIEW_DISABLED_SITE
                    | self::MANAGE_CONTENT
                    | self::MANAGE_TAXONOMY
                    | self::MANAGE_USERS
                    | $adminPanelBitsMask,
                'is_stock' => 1,
            ],
            [
                'name' => 'Editor',
                'slug' => 'editor',
                'permission_mask' => self::PANEL_LOGIN
                    | self::VIEW_PUBLIC_SITE
                    | self::VIEW_PRIVATE_SITE
                    | self::MANAGE_CONTENT
                    | $editorPanelBitsMask,
                'is_stock' => 1,
            ],
            [
                'name' => 'User',
                'slug' => 'user',
                'permission_mask' => self::VIEW_PUBLIC_SITE | self::VIEW_PRIVATE_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'Guest',
                'slug' => 'guest',
                'permission_mask' => self::VIEW_PUBLIC_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'Validating',
                'slug' => 'validating',
                'permission_mask' => self::VIEW_PUBLIC_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'Banned',
                'slug' => 'banned',
                'permission_mask' => 0,
                'is_stock' => 1,
            ],
        ];
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
        return [
            'pages' => [
                'label' => 'Pages',
                'view' => self::PAGES_VIEW,
                'create' => self::PAGES_CREATE,
                'edit' => self::PAGES_EDIT,
                'delete' => self::PAGES_DELETE,
            ],
            'channels' => [
                'label' => 'Channels',
                'view' => self::CHANNELS_VIEW,
                'create' => self::CHANNELS_CREATE,
                'edit' => self::CHANNELS_EDIT,
                'delete' => self::CHANNELS_DELETE,
            ],
            'categories' => [
                'label' => 'Categories',
                'view' => self::CATEGORIES_VIEW,
                'create' => self::CATEGORIES_CREATE,
                'edit' => self::CATEGORIES_EDIT,
                'delete' => self::CATEGORIES_DELETE,
            ],
            'tags' => [
                'label' => 'Tags',
                'view' => self::TAGS_VIEW,
                'create' => self::TAGS_CREATE,
                'edit' => self::TAGS_EDIT,
                'delete' => self::TAGS_DELETE,
            ],
            'redirects' => [
                'label' => 'Redirects',
                'view' => self::REDIRECTS_VIEW,
                'create' => self::REDIRECTS_CREATE,
                'edit' => self::REDIRECTS_EDIT,
                'delete' => self::REDIRECTS_DELETE,
            ],
            'users' => [
                'label' => 'Users',
                'view' => self::USERS_VIEW,
                'create' => self::USERS_CREATE,
                'edit' => self::USERS_EDIT,
                'delete' => self::USERS_DELETE,
            ],
            'groups' => [
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
                'delete' => self::THEMES_DELETE,
            ],
            'extensions' => [
                'label' => 'Extensions',
                'view' => self::EXTENSIONS_VIEW,
                'create' => self::EXTENSIONS_CREATE,
                'edit' => self::EXTENSIONS_EDIT,
                'delete' => self::EXTENSIONS_DELETE,
            ],
            'configuration' => [
                'label' => 'Configuration',
                'view' => self::CONFIGURATION_VIEW,
                'create' => self::CONFIGURATION_CREATE,
                'edit' => self::CONFIGURATION_EDIT,
                'delete' => self::CONFIGURATION_DELETE,
            ],
        ];
    }

    /**
     * Returns stock panel route permission row by route key.
     *
     * @return array{label: string, view: int, create: int, edit: int, delete: int}|null
     */
    public static function stockPanelRoutePermission(string $routeKey): ?array
    {
        $definitions = self::stockPanelRoutePermissions();
        $normalized = strtolower(trim($routeKey));
        return $definitions[$normalized] ?? null;
    }

    /**
     * Returns all stock panel permission bits.
     *
     * @return array<int, int>
     */
    public static function allStockPanelBits(): array
    {
        $bits = [];
        foreach (self::stockPanelRoutePermissions() as $permissionRow) {
            $bits[] = (int) $permissionRow['view'];
            $bits[] = (int) $permissionRow['create'];
            $bits[] = (int) $permissionRow['edit'];
            $bits[] = (int) $permissionRow['delete'];
        }

        return $bits;
    }

    /**
     * Returns content-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function contentPanelBits(): array
    {
        $row = self::stockPanelRoutePermission('pages');
        if ($row === null) {
            return [];
        }

        return [(int) $row['view'], (int) $row['create'], (int) $row['edit'], (int) $row['delete']];
    }

    /**
     * Returns taxonomy-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function taxonomyPanelBits(): array
    {
        $routes = ['channels', 'categories', 'tags', 'redirects', 'routing'];
        $bits = [];
        foreach ($routes as $route) {
            $row = self::stockPanelRoutePermission($route);
            if ($row === null) {
                continue;
            }

            $bits[] = (int) $row['view'];
            $bits[] = (int) $row['create'];
            $bits[] = (int) $row['edit'];
            $bits[] = (int) $row['delete'];
        }

        return $bits;
    }

    /**
     * Returns users-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function usersPanelBits(): array
    {
        $row = self::stockPanelRoutePermission('users');
        if ($row === null) {
            return [];
        }

        return [(int) $row['view'], (int) $row['create'], (int) $row['edit'], (int) $row['delete']];
    }

    /**
     * Returns groups-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function groupsPanelBits(): array
    {
        $row = self::stockPanelRoutePermission('groups');
        if ($row === null) {
            return [];
        }

        return [(int) $row['view'], (int) $row['create'], (int) $row['edit'], (int) $row['delete']];
    }

    /**
     * Returns system-management panel permission bits.
     *
     * @return array<int, int>
     */
    public static function systemPanelBits(): array
    {
        $routes = ['configuration', 'themes', 'extensions'];
        $bits = [];
        foreach ($routes as $route) {
            $row = self::stockPanelRoutePermission($route);
            if ($row === null) {
                continue;
            }

            $bits[] = (int) $row['view'];
            $bits[] = (int) $row['create'];
            $bits[] = (int) $row['edit'];
            $bits[] = (int) $row['delete'];
        }

        return $bits;
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
