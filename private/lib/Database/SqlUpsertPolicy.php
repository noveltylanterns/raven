<?php

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Shared backend-aware SQL helper for duplicate-safe idempotent inserts.
 */
final class SqlUpsertPolicy
{
    /**
     * @param array<int, string> $columns
     * @param array<int, string> $conflictColumns
     */
    public function idempotentInsertSql(string $driver, string $table, array $columns, array $conflictColumns): string
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
