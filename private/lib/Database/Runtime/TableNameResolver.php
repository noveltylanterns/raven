<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Runtime;

/**
 * Resolves runtime table names for app-db and auth-db query contexts.
 */
final class TableNameResolver
{
    public static function appTable(string $driver, string $prefix, string $table): string
    {
        if ($driver !== 'sqlite') {
            return $prefix . $table;
        }

        if (str_starts_with($table, 'ext_')) {
            return 'extensions.' . $table;
        }

        return match ($table) {
            'pages' => 'main.pages',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'redirects' => 'taxonomy.redirects',
            'page_categories' => 'main.page_categories',
            'page_tags' => 'main.page_tags',
            'page_images' => 'main.page_images',
            'page_image_variants' => 'main.page_image_variants',
            'users' => 'auth.users',
            'groups' => 'auth.groups',
            'user_groups' => 'auth.user_groups',
            'invite_tokens' => 'auth.invite_tokens',
            'login_failures' => 'auth.login_failures',
            default => 'main.' . $table,
        };
    }

    public static function authTable(string $driver, string $prefix, string $table): string
    {
        if ($driver !== 'sqlite') {
            return $prefix . $table;
        }

        // Auth database queries run against the auth connection directly.
        return $table;
    }
}
