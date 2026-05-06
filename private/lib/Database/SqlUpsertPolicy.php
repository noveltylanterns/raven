<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/SqlUpsertPolicy.php
 * Shared backend-aware SQL helper for duplicate-safe idempotent inserts.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Shared backend-aware SQL helper for duplicate-safe idempotent inserts.
 */
final class SqlUpsertPolicy
{
    /**
     * Returns a driver-appropriate INSERT that silently skips duplicate rows.
     *
     * MySQL uses INSERT IGNORE; SQLite and PostgreSQL use ON CONFLICT DO NOTHING.
     *
     * @param string        $driver          PDO driver name: sqlite, mysql, or pgsql.
     * @param string        $table           Target table name.
     * @param array<int, string> $columns    Ordered list of column names to insert.
     * @param array<int, string> $conflictColumns Columns that define the uniqueness conflict (SQLite/PgSQL).
     * @return string Raw SQL string ready for use with PDO::prepare().
     */
    public function insertIgnoreSql(string $driver, string $table, array $columns, array $conflictColumns): string
    {
        $columnList = implode(', ', $columns);
        $valuePlaceholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));

        if (strtolower(trim($driver)) === 'mysql') {
            return 'INSERT IGNORE INTO ' . $table . ' (' . $columnList . ')
                    VALUES (' . $valuePlaceholders . ')';
        }

        $conflicts = implode(', ', $conflictColumns);
        if (strtolower(trim($driver)) === 'sqlite') {
            return 'INSERT INTO ' . $table . ' (' . $columnList . ')
                    VALUES (' . $valuePlaceholders . ')
                    ON CONFLICT(' . $conflicts . ') DO NOTHING';
        }

        return 'INSERT INTO ' . $table . ' (' . $columnList . ')
                VALUES (' . $valuePlaceholders . ')
                ON CONFLICT (' . $conflicts . ') DO NOTHING';
    }
}
