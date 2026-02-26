<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Auth/PanelAccess.php
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
    /** Legacy bit used by historical "Manage Own Preferences" capability. */
    private const LEGACY_MANAGE_SELF_BIT = 32;

    /** Allows access to public-site mode frontend routes/content. */
    public const VIEW_PUBLIC_SITE = 128;

    /** Allows access to private-site mode frontend routes/content. */
    public const VIEW_PRIVATE_SITE = 256;

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

    /** @deprecated Merged into PANEL_LOGIN for panel minimum access behavior. */
    public const MANAGE_SELF = self::PANEL_LOGIN;

    /**
     * Returns required stock groups.
     *
     * @return array<int, array{name: string, slug: string, permission_mask: int, is_stock: int}>
     */
    public static function stockGroups(): array
    {
        return [
            [
                'name' => 'Super Admin',
                'slug' => 'super',
                'permission_mask' => self::PANEL_LOGIN
                    | self::VIEW_PUBLIC_SITE
                    | self::VIEW_PRIVATE_SITE
                    | self::MANAGE_CONTENT
                    | self::MANAGE_TAXONOMY
                    | self::MANAGE_USERS
                    | self::MANAGE_GROUPS
                    | self::MANAGE_CONFIGURATION,
                'is_stock' => 1,
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'permission_mask' => self::PANEL_LOGIN
                    | self::VIEW_PUBLIC_SITE
                    | self::VIEW_PRIVATE_SITE
                    | self::MANAGE_CONTENT
                    | self::MANAGE_TAXONOMY
                    | self::MANAGE_USERS,
                'is_stock' => 1,
            ],
            [
                'name' => 'Editor',
                'slug' => 'editor',
                'permission_mask' => self::PANEL_LOGIN
                    | self::VIEW_PUBLIC_SITE
                    | self::VIEW_PRIVATE_SITE
                    | self::MANAGE_CONTENT,
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
        // Backward-compatible support for stored legacy MANAGE_SELF-only masks.
        return (bool) ($mask & (self::PANEL_LOGIN | self::LEGACY_MANAGE_SELF_BIT));
    }

    /**
     * Checks user-management capability.
     */
    public static function canManageUsers(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_USERS);
    }

    /**
     * Checks group-management capability.
     */
    public static function canManageGroups(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_GROUPS);
    }

    /**
     * Checks content-management capability.
     */
    public static function canManageContent(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_CONTENT);
    }

    /**
     * Checks system-configuration capability.
     */
    public static function canManageConfiguration(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_CONFIGURATION);
    }

    /**
     * Checks taxonomy-management capability.
     */
    public static function canManageTaxonomy(int $mask): bool
    {
        return (bool) ($mask & self::MANAGE_TAXONOMY);
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
}
