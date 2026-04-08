<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Lib\Auth\PanelAccess;

/**
 * Holds stock panel permission-route/group catalog definitions.
 */
final class PanelAccessCatalog
{
    /**
     * @return array<string, array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}>
     */
    public static function stockPanelRoutePermissions(): array
    {
        return [
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
    }

    /**
     * @return array{label: string, view: int, create?: int, edit?: int, delete?: int, uninstall?: int}|null
     */
    public static function stockPanelRoutePermission(string $routeKey): ?array
    {
        $definitions = self::stockPanelRoutePermissions();
        $normalized = strtolower(trim($routeKey));
        return $definitions[$normalized] ?? null;
    }

    /**
     * @return array<int, int>
     */
    public static function allStockPanelBits(): array
    {
        $bits = [];
        foreach (self::stockPanelRoutePermissions() as $permissionRow) {
            foreach (['view', 'create', 'edit', 'delete', 'uninstall'] as $action) {
                $bit = (int) ($permissionRow[$action] ?? 0);
                if ($bit > 0) {
                    $bits[] = $bit;
                }
            }
        }

        return $bits;
    }

    /**
     * @return array<int, int>
     */
    public static function contentPanelBits(): array
    {
        return self::routeBits('page');
    }

    /**
     * @return array<int, int>
     */
    public static function taxonomyPanelBits(): array
    {
        return self::routesBits(['channel', 'category', 'tag', 'redirect', 'routing']);
    }

    /**
     * @return array<int, int>
     */
    public static function usersPanelBits(): array
    {
        return self::routeBits('user');
    }

    /**
     * @return array<int, int>
     */
    public static function groupsPanelBits(): array
    {
        return self::routeBits('group');
    }

    /**
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
     * @return array<int, array{name: string, slug: string, permissions: int, is_stock: int}>
     */
    public static function stockGroups(): array
    {
        $allStockPanelBitsMask = self::maskFromBits(self::allStockPanelBits());

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
     * @param array<int, string> $routes
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

    /**
     * @param array<int, int> $bits
     */
    private static function maskFromBits(array $bits): int
    {
        $mask = 0;
        foreach ($bits as $bit) {
            $mask |= (int) $bit;
        }

        return $mask;
    }
}
