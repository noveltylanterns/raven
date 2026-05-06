<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaIntrospector.php
 * Cross-driver schema introspection and DDL safety helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;

/**
 * Cross-driver schema introspection and DDL safety helpers.
 */
final class SchemaIntrospector
{
    /**
     * Returns true when a column exists in a table, dispatching by driver.
     *
     * @param PDO    $db     Database connection to inspect.
     * @param string $driver PDO driver name: sqlite, mysql, or pgsql.
     * @param string $table  Table name, optionally schema-qualified (SQLite only).
     * @param string $column Column name to look up.
     * @return bool True when the column exists in the named table.
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
     *
     * SQLite uses CREATE INDEX IF NOT EXISTS and does not need an existence check; returns false.
     *
     * @param PDO    $db        Database connection to inspect.
     * @param string $driver    PDO driver name: sqlite, mysql, or pgsql.
     * @param string $table     Table the index belongs to.
     * @param string $indexName Index name to look up.
     * @return bool True when the index exists, always false for SQLite.
     */
    public function indexExists(PDO $db, string $driver, string $table, string $indexName): bool
    {
        if ($driver === 'mysql') {
            return $this->indexExistsMySql($db, $table, $indexName);
        }

        if ($driver === 'pgsql') {
            return $this->indexExistsPgSql($db, $table, $indexName);
        }

        return false;
    }

    /**
     * Returns true when a table exists, dispatching by driver.
     *
     * @param PDO    $db     Database connection to inspect.
     * @param string $driver PDO driver name: sqlite, mysql, or pgsql.
     * @param string $table  Table name to look up.
     * @return bool True when the table exists in the active schema.
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

    /**
     * Returns true when a table exists in a named SQLite schema (attached DB).
     *
     * @param PDO    $db     SQLite database connection to inspect.
     * @param string $schema Attached schema name (must be a plain identifier).
     * @param string $table  Table name to look up within the schema.
     * @return bool True when the table exists in the named schema.
     */
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

    /**
     * Returns true when a named index exists in a MySQL schema.
     *
     * @param PDO    $db        MySQL database connection to inspect.
     * @param string $table     Table the index belongs to.
     * @param string $indexName Index name to look up.
     * @return bool True when the index exists in the current database.
     */
    public function indexExistsMySql(PDO $db, string $table, string $indexName): bool
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

    /**
     * Returns true when a named index exists in a PostgreSQL schema.
     *
     * @param PDO    $db        PostgreSQL database connection to inspect.
     * @param string $table     Table the index belongs to.
     * @param string $indexName Index name to look up.
     * @return bool True when the index exists in the current schema.
     */
    public function indexExistsPgSql(PDO $db, string $table, string $indexName): bool
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

    /**
     * Wraps a PostgreSQL identifier in double-quotes, escaping any interior quotes.
     *
     * @param string $identifier Unquoted identifier such as a table or column name.
     * @return string Safely double-quoted identifier for use in raw SQL strings.
     */
    public function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Returns true when a PDOException message indicates a schema object already exists.
     *
     * Used to swallow idempotent DDL failures (CREATE TABLE IF NOT EXISTS equivalents)
     * across all three supported drivers.
     *
     * @param \PDOException $exception Exception thrown by a DDL statement.
     * @return bool True when the error message matches a known already-exists pattern.
     */
    public function isAlreadyExistsError(\PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'duplicate object')
            || str_contains($message, 'relation') && str_contains($message, 'exists');
    }

    /**
     * Returns true when a column exists in a SQLite table, with optional schema-prefix support.
     *
     * Validates identifier names before issuing the PRAGMA to prevent SQL injection via
     * schema-qualified table names (e.g., "attached.tablename").
     *
     * @param PDO    $db     SQLite database connection to inspect.
     * @param string $table  Table name, optionally schema-qualified (e.g., "main.pages").
     * @param string $column Column name to look up.
     * @return bool True when the column exists.
     */
    private function appColumnExistsSqlite(PDO $db, string $table, string $column): bool
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

    /**
     * Returns true when a column exists in a MySQL table.
     *
     * @param PDO    $db     MySQL database connection to inspect.
     * @param string $table  Table name within the current database.
     * @param string $column Column name to look up.
     * @return bool True when the column exists.
     */
    private function appColumnExistsMySql(PDO $db, string $table, string $column): bool
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

    /**
     * Returns true when a column exists in a PostgreSQL table.
     *
     * @param PDO    $db     PostgreSQL database connection to inspect.
     * @param string $table  Table name within the current schema.
     * @param string $column Column name to look up.
     * @return bool True when the column exists.
     */
    private function appColumnExistsPgSql(PDO $db, string $table, string $column): bool
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
}
