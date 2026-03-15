<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

/**
 * Resolves logical schema table names to backend-specific physical names.
 */
final class TableNameResolver
{
    public function resolve(string $driver, string $prefix, string $table): string
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
            'groups' => 'auth.groups',
            'user_groups' => 'auth.user_groups',
            'login_failures' => 'auth.login_failures',
            default => 'main.' . $table,
        };
    }
}

