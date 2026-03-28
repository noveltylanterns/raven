<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;

/**
 * Cross-driver schema introspection and DDL safety helpers.
 */
final class SchemaIntrospector
{
    public function authUsersTableExists(PDO $db, string $driver, string $prefix): bool
    {
        $table = $prefix . 'users';

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1'
            );
            $stmt->execute([
                ':type' => 'table',
                ':name' => $table,
            ]);

            return $stmt->fetchColumn() !== false;
        }

        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                 LIMIT 1'
            );
            $stmt->execute([':table_name' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute([':table_name' => $table]);

        return $stmt->fetchColumn() !== null;
    }

    /**
     * Returns true when a column exists in a table, dispatching by driver.
     */
    public function columnExists(PDO $db, string $driver, string $table, string $column): bool
    {
        if ($driver === 'sqlite') {
            return $this->appColumnExistsSqlite($db, $table, $column);
        }

        if ($driver === 'mysql') {
            return $this->appColumnExistsMySql($db, $table, $column);
        }

        return $this->appColumnExistsPgSql($db, $table, $column);
    }

    /**
     * Returns true when a named index exists, dispatching by driver.
     * SQLite uses CREATE INDEX IF NOT EXISTS and does not need this check; returns false.
     */
    public function indexExists(PDO $db, string $driver, string $table, string $indexName): bool
    {
        if ($driver === 'mysql') {
            return $this->mySqlIndexExists($db, $table, $indexName);
        }

        if ($driver === 'pgsql') {
            return $this->pgSqlIndexExists($db, $table, $indexName);
        }

        return false;
    }

    /**
     * Returns true when a table exists, dispatching by driver.
     */
    public function tableExists(PDO $db, string $driver, string $table): bool
    {
        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1'
            );
            $stmt->execute([':type' => 'table', ':name' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                 LIMIT 1'
            );
            $stmt->execute([':table_name' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute([':table_name' => $table]);

        return $stmt->fetchColumn() !== null;
    }

    public function authColumnExistsSqlite(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return false;
        }

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    public function authColumnExistsMySql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function authColumnExistsPgSql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function appColumnExistsSqlite(PDO $db, string $table, string $column): bool
    {
        $schema = null;
        $tableName = $table;

        if (str_contains($table, '.')) {
            [$schemaPart, $tablePart] = explode('.', $table, 2);
            $schema = trim($schemaPart);
            $tableName = trim($tablePart);

            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $schema) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $tableName)) {
                return false;
            }
        } elseif (!preg_match('/^[a-z_][a-z0-9_]*$/i', $tableName)) {
            return false;
        }

        $pragma = $schema === null
            ? 'PRAGMA table_info(' . $tableName . ')'
            : 'PRAGMA ' . $schema . '.table_info(' . $tableName . ')';

        $stmt = $db->query($pragma);
        if ($stmt === false) {
            return false;
        }

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    public function sqliteTableExists(PDO $db, string $schema, string $table): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $schema)) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM ' . $schema . '.sqlite_master
             WHERE type = :type AND name = :name
             LIMIT 1'
        );
        $stmt->execute([
            ':type' => 'table',
            ':name' => $table,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function appColumnExistsMySql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function appColumnExistsPgSql(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function mySqlIndexExists(PDO $db, string $table, string $indexName): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':index_name' => $indexName,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function pgSqlIndexExists(PDO $db, string $table, string $indexName): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
             FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = :table_name
               AND indexname = :index_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':index_name' => $indexName,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function isAlreadyExistsSchemaError(\PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'duplicate object')
            || str_contains($message, 'relation') && str_contains($message, 'exists');
    }
}
