<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/SqlInsert.php
 * Shared backend-aware SQL helper for INSERT statement construction.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

use RuntimeException;

/**
 * Shared backend-aware SQL helper for INSERT statement construction.
 */
final class SqlInsert
{
    /**
     * Returns a plain INSERT statement with named placeholders for each column.
     *
     * @param string             $table   Target table name.
     * @param array<int, string> $columns Ordered list of column names to insert.
     * @return string Raw SQL string ready for use with PDO::prepare().
     */
    public function insert(string $table, array $columns): string
    {
        $columnList = implode(', ', $columns);
        $valuePlaceholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));

        return 'INSERT INTO ' . $table . ' (' . $columnList . ')
                VALUES (' . $valuePlaceholders . ')';
    }

    /**
     * Returns a driver-appropriate INSERT that silently skips duplicate rows.
     *
     * MySQL uses INSERT IGNORE; SQLite and PostgreSQL use ON CONFLICT DO NOTHING.
     *
     * @param string        $driver          PDO driver name: sqlite, mysql, or pgsql.
     * @param string        $table           Target table name.
     * @param array<int, string> $columns         Ordered list of column names to insert.
     * @param array<int, string> $conflictColumns Columns that define the uniqueness conflict (SQLite/PgSQL).
     * @return string Raw SQL string ready for use with PDO::prepare().
     * @throws RuntimeException When non-MySQL drivers are missing conflict columns.
     */
    public function insertIgnore(string $driver, string $table, array $columns, array $conflictColumns): string
    {
        $columnList = implode(', ', $columns);
        $valuePlaceholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));

        if (strtolower(trim($driver)) === 'mysql') {
            return 'INSERT IGNORE INTO ' . $table . ' (' . $columnList . ')
                    VALUES (' . $valuePlaceholders . ')';
        }

        if ($conflictColumns === []) {
            throw new RuntimeException('Conflict columns are required for non-MySQL duplicate-safe INSERT statements.');
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
