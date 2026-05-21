<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/SqlTable.php
 * Resolves prefixed table names for SQL query contexts.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Resolves runtime table names for SQL query contexts.
 */
final class SqlTable
{
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
}
