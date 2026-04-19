<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/TableNameResolver.php
 * Resolves prefixed table names for app-db and auth-db query contexts.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Resolves runtime table names for app-db and auth-db query contexts.
 *
 * Centralises prefix application so repositories never build table names inline.
 * The driver parameter is reserved for future per-driver quoting strategies.
 */
final class TableNameResolver
{
    /**
     * Returns the prefixed app-database table name for a given base table.
     *
     * Instance form used by schema/bootstrap services that carry a shared
     * resolver dependency rather than calling the static helpers directly.
     *
     * @param string $driver Database driver identifier (reserved for future quoting strategies).
     * @param string $prefix Configured table prefix (e.g. `rvn_`).
     * @param string $table Base table name without prefix.
     * @return string Fully prefixed table name ready for use in SQL.
     */
    public function resolve(string $driver, string $prefix, string $table): string
    {
        return self::appTable($driver, $prefix, $table);
    }

    /**
     * Returns the prefixed app-database table name for a given base table.
     *
     * @param string $driver Database driver identifier (reserved for future quoting strategies).
     * @param string $prefix Configured table prefix (e.g. `rvn_`).
     * @param string $table  Base table name without prefix.
     * @return string Fully prefixed table name ready for use in SQL.
     */
    public static function appTable(string $driver, string $prefix, string $table): string
    {
        return $prefix . $table;
    }

    /**
     * Returns the prefixed auth-database table name for a given base table.
     *
     * @param string $driver Database driver identifier (reserved for future quoting strategies).
     * @param string $prefix Configured table prefix (e.g. `rvn_`).
     * @param string $table  Base table name without prefix.
     * @return string Fully prefixed table name ready for use in SQL.
     */
    public static function authTable(string $driver, string $prefix, string $table): string
    {
        return $prefix . $table;
    }
}
