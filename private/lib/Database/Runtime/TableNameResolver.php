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
        return $prefix . $table;
    }

    public static function authTable(string $driver, string $prefix, string $table): string
    {
        return $prefix . $table;
    }
}
