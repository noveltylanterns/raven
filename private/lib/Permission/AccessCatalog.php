<?php

/**
 * RAVEN CMS
 * ~/private/lib/Permission/AccessCatalog.php
 * Stock panel permission-route and group catalog definitions.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Permission;

/**
 * Stock panel permission-route map and group seed definitions.
 */
final class AccessCatalog
{
    /**
     * @var array<string, array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}>|null
     */
    private static ?array $stockPanelRoutePermissionsCache = null;

    /** @var array<int, int>|null */
    private static ?array $allStockPanelBitsCache = null;

    /**
     * Returns the full stock panel route permission map, keyed by route key.
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
                'view' => PanelAccess::PAGES_VIEW,
                'create' => PanelAccess::PAGES_CREATE,
                'edit' => PanelAccess::PAGES_EDIT,
                'delete' => PanelAccess::PAGES_DELETE,
            ],
            'channel' => [
                'label' => 'Channels',
                'view' => PanelAccess::CHANNELS_VIEW,
                'create' => PanelAccess::CHANNELS_CREATE,
                'edit' => PanelAccess::CHANNELS_EDIT,
                'delete' => PanelAccess::CHANNELS_DELETE,
            ],
            'category' => [
                'label' => 'Categories',
                'view' => PanelAccess::CATEGORIES_VIEW,
                'create' => PanelAccess::CATEGORIES_CREATE,
                'edit' => PanelAccess::CATEGORIES_EDIT,
                'delete' => PanelAccess::CATEGORIES_DELETE,
            ],
            'tag' => [
                'label' => 'Tags',
                'view' => PanelAccess::TAGS_VIEW,
                'create' => PanelAccess::TAGS_CREATE,
                'edit' => PanelAccess::TAGS_EDIT,
                'delete' => PanelAccess::TAGS_DELETE,
            ],
            'redirect' => [
                'label' => 'Redirects',
                'view' => PanelAccess::REDIRECTS_VIEW,
                'create' => PanelAccess::REDIRECTS_CREATE,
                'edit' => PanelAccess::REDIRECTS_EDIT,
                'delete' => PanelAccess::REDIRECTS_DELETE,
            ],
            'user' => [
                'label' => 'Users',
                'view' => PanelAccess::USERS_VIEW,
                'create' => PanelAccess::USERS_CREATE,
                'edit' => PanelAccess::USERS_EDIT,
                'delete' => PanelAccess::USERS_DELETE,
            ],
            'group' => [
                'label' => 'Groups',
                'view' => PanelAccess::GROUPS_VIEW,
                'create' => PanelAccess::GROUPS_CREATE,
                'edit' => PanelAccess::GROUPS_EDIT,
                'delete' => PanelAccess::GROUPS_DELETE,
            ],
            'routing' => [
                'label' => 'Routing',
                'view' => PanelAccess::ROUTING_VIEW,
                'create' => PanelAccess::ROUTING_CREATE,
                'edit' => PanelAccess::ROUTING_EDIT,
                'delete' => PanelAccess::ROUTING_DELETE,
            ],
            'themes' => [
                'label' => 'Themes',
                'view' => PanelAccess::THEMES_VIEW,
                'create' => PanelAccess::THEMES_CREATE,
                'edit' => PanelAccess::THEMES_EDIT,
                'uninstall' => PanelAccess::THEMES_UNINSTALL,
            ],
            'extensions' => [
                'label' => 'Extensions',
                'view' => PanelAccess::EXTENSIONS_VIEW,
                'create' => PanelAccess::EXTENSIONS_CREATE,
                'edit' => PanelAccess::EXTENSIONS_EDIT,
                'uninstall' => PanelAccess::EXTENSIONS_UNINSTALL,
            ],
            'configuration' => [
                'label' => 'Configuration',
                'view' => PanelAccess::CONFIGURATION_VIEW,
                'create' => PanelAccess::CONFIGURATION_CREATE,
                'edit' => PanelAccess::CONFIGURATION_EDIT,
                'delete' => PanelAccess::CONFIGURATION_DELETE,
            ],
            'logs' => [
                'label' => 'Event Log',
                // View and clear (delete) reuse the Configuration permission bits — the event log
                // is a system-level diagnostic tool, not a content-management surface.
                'view' => PanelAccess::CONFIGURATION_VIEW,
                'delete' => PanelAccess::CONFIGURATION_DELETE,
            ],
            'update' => [
                'label' => 'Update Raven',
                'view' => PanelAccess::MANAGE_CONFIGURATION,
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
     * Returns a flat list of every individual stock panel route-level permission bit.
     *
     * @return array<int, int>
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
     * Returns page-route permission bits.
     *
     * @return array<int, int>
     */
    public static function contentPanelBits(): array
    {
        return self::routeBits('page');
    }

    /**
     * Returns taxonomy-route permission bits (channel, category, tag, redirect, routing).
     *
     * @return array<int, int>
     */
    public static function taxonomyPanelBits(): array
    {
        return self::routesBits(['channel', 'category', 'tag', 'redirect', 'routing']);
    }

    /**
     * Returns user-route permission bits.
     *
     * @return array<int, int>
     */
    public static function usersPanelBits(): array
    {
        return self::routeBits('user');
    }

    /**
     * Returns group-route permission bits.
     *
     * @return array<int, int>
     */
    public static function groupsPanelBits(): array
    {
        return self::routeBits('group');
    }

    /**
     * Returns system-route permission bits (configuration, themes, extensions, plus MANAGE_CONFIGURATION).
     *
     * @return array<int, int>
     */
    public static function systemPanelBits(): array
    {
        return array_merge(
            [PanelAccess::MANAGE_CONFIGURATION],
            self::routesBits(['configuration', 'themes', 'extensions'])
        );
    }

    /**
     * Returns the stock group seed rows used during schema installation.
     *
     * @return array<int, array{name: string, slug: string, permissions: int, is_stock: int}>
     */
    public static function stockGroups(): array
    {
        $allStockPanelBitsMask = PanelAccess::maskFromBits(self::allStockPanelBits());

        return [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'permissions' => PanelAccess::PANEL_LOGIN
                    | PanelAccess::VIEW_PUBLIC_SITE
                    | PanelAccess::VIEW_PRIVATE_SITE
                    | PanelAccess::VIEW_DISABLED_SITE
                    | PanelAccess::MANAGE_CONTENT
                    | PanelAccess::MANAGE_TAXONOMY
                    | PanelAccess::MANAGE_USERS
                    | PanelAccess::MANAGE_GROUPS
                    | PanelAccess::MANAGE_CONFIGURATION
                    | $allStockPanelBitsMask,
                'is_stock' => 1,
            ],
            [
                'name' => 'Guest',
                'slug' => 'guest',
                'permissions' => PanelAccess::VIEW_PUBLIC_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'Validating',
                'slug' => 'validating',
                'permissions' => PanelAccess::VIEW_PUBLIC_SITE,
                'is_stock' => 1,
            ],
            [
                'name' => 'User',
                'slug' => 'user',
                'permissions' => PanelAccess::VIEW_PUBLIC_SITE | PanelAccess::VIEW_PRIVATE_SITE,
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
