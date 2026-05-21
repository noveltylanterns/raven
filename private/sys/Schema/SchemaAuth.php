<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaAuth.php
 * Ensures auth-side schema objects and Raven-specific user profile columns.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;
use RuntimeException;

/**
 * Ensures auth-side schema objects and user preference columns.
 */
final class SchemaAuth
{
    private SchemaIntrospector $introspector;

    /**
     * Wires the schema introspector used for auth table/column/index checks.
     *
     * @param SchemaIntrospector $introspector Cross-driver schema inspection helper.
     * @return void
     */
    public function __construct(SchemaIntrospector $introspector)
    {
        $this->introspector = $introspector;
    }

    /**
     * Creates the Delight auth schema if absent, then ensures Raven profile columns.
     *
     * @param PDO    $authDb Auth database connection.
     * @param string $driver PDO driver name (sqlite, mysql, pgsql).
     * @param string $prefix Table name prefix (may be empty).
     * @throws RuntimeException When required Delight SQL schema files are missing.
     */
    public function ensureAuthSchema(PDO $authDb, string $driver, string $prefix): void
    {
        if (!$this->introspector->tableExists($authDb, $driver, $prefix . 'users')) {
            $schema = $this->loadDelightSchema($driver);

            if ($schema === null) {
                throw new RuntimeException('Delight Auth SQL schema files are missing. Install composer dependencies before bootstrap.');
            }

            // Prefix auth tables in shared-DB modes for namespace isolation.
            if ($prefix !== '') {
                $schema = $this->applyAuthPrefix($schema, $prefix);
            }

            $this->executeSqlBatch($authDb, $schema);
        }

        // Profile columns are required by User Preferences and may be missing
        // on previously created Delight installations, so always ensure them.
        $this->ensureAuthUserPreferenceColumns($authDb, $driver, $prefix);
    }

    /**
     * Creates the invite-token table and its indexes for the given driver.
     *
     * @param PDO    $authDb Auth database connection.
     * @param string $driver PDO driver name (sqlite, mysql, pgsql).
     * @param string $prefix Table name prefix (may be empty).
     */
    public function ensureInviteTokenSchema(PDO $authDb, string $driver, string $prefix): void
    {
        $table = $prefix . 'auth_invites';

        if ($driver === 'sqlite') {
            $authDb->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT NOT NULL UNIQUE,
                value TEXT NULL,
                hint TEXT NOT NULL,
                reusable INTEGER NOT NULL DEFAULT 0,
                uses INTEGER NOT NULL DEFAULT 0,
                expires INTEGER NULL,
                last_used INTEGER NULL,
                created TEXT NOT NULL,
                creator INTEGER NULL
            )');
            $authDb->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $table . '_hash ON ' . $table . ' (hash)');
            $authDb->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_expires ON ' . $table . ' (expires)');
            return;
        }

        if ($driver === 'mysql') {
            $authDb->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                hash CHAR(64) NOT NULL,
                value VARCHAR(191) NULL,
                hint VARCHAR(16) NOT NULL,
                reusable TINYINT(1) NOT NULL DEFAULT 0,
                uses INT UNSIGNED NOT NULL DEFAULT 0,
                expires BIGINT UNSIGNED NULL,
                last_used BIGINT UNSIGNED NULL,
                created DATETIME NOT NULL,
                creator BIGINT UNSIGNED NULL,
                UNIQUE KEY (hash),
                INDEX (expires)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            return;
        }

        // PostgreSQL.
        $authDb->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
            id BIGSERIAL PRIMARY KEY,
            hash CHAR(64) NOT NULL UNIQUE,
            value VARCHAR(191) NULL,
            hint VARCHAR(16) NOT NULL,
            reusable SMALLINT NOT NULL DEFAULT 0,
            uses INTEGER NOT NULL DEFAULT 0,
            expires BIGINT NULL,
            last_used BIGINT NULL,
            created TIMESTAMP NOT NULL,
            creator BIGINT NULL
        )');
        $authDb->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_expires ON ' . $table . ' (expires)');
    }

    /**
     * Adds Raven-specific profile columns to the auth users table when missing.
     *
     * Called on every bootstrap so new columns added in updates are applied
     * automatically without requiring a manual migration step.
     *
     * @param PDO    $db     Auth database connection.
     * @param string $driver PDO driver name (sqlite, mysql, pgsql).
     * @param string $prefix Table name prefix (may be empty).
     */
    public function ensureAuthUserPreferenceColumns(PDO $db, string $driver, string $prefix): void
    {
        $usersTable = $prefix . 'users';

        if ($driver === 'sqlite') {
            // SQLite ADD COLUMN is safe to call repeatedly; each path is guarded
            // by a column-existence check before issuing DDL.
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'theme')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme TEXT NOT NULL DEFAULT \'default\'');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'name')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN name TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'bio')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN bio TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'avatar')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'cover_image')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN cover_image TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'string')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN string TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'contact')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'two_factor')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'group')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN "group" INTEGER NULL');
            }
            if (!$this->introspector->columnExists($db, 'sqlite', $usersTable, 'timezone')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN timezone TEXT NOT NULL DEFAULT \'\'');
            }
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $usersTable . '_string ON ' . $usersTable . ' (string)');

            $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'theme')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'name')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN name VARCHAR(160) NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'avatar')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar VARCHAR(255) NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'cover_image')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN cover_image VARCHAR(255) NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'contact')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'two_factor')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor LONGTEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'bio')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN bio TEXT NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'string')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN string VARCHAR(128) NULL');
            }
            if (!$this->introspector->indexExistsMySql($db, $usersTable, 'uniq_' . $usersTable . '_string')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD UNIQUE INDEX uniq_' . $usersTable . '_string (string)');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'group')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN `group` BIGINT UNSIGNED NULL');
            }
            if (!$this->introspector->columnExists($db, 'mysql', $usersTable, 'timezone')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT \'\'');
            }

            $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        // PostgreSQL.
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'theme')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'name')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN name VARCHAR(160) NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'avatar')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN avatar VARCHAR(255) NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'cover_image')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN cover_image VARCHAR(255) NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'contact')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN contact TEXT NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'two_factor')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN two_factor TEXT NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'bio')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN bio TEXT NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'string')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN string VARCHAR(128) NULL');
        }
        if (!$this->introspector->indexExistsPgSql($db, $usersTable, 'uniq_' . $usersTable . '_string')) {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ' . $this->introspector->quotePgIdentifier('uniq_' . $usersTable . '_string') . ' ON ' . $this->introspector->quotePgIdentifier($usersTable) . ' (string)');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'group')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN "group" BIGINT NULL');
        }
        if (!$this->introspector->columnExists($db, 'pgsql', $usersTable, 'timezone')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT \'\'');
        }
        $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
    }

    /**
     * Loads the correct Delight Auth SQL schema file for the given driver.
     *
     * @param string $driver PDO driver name (sqlite, mysql, pgsql).
     * @return string|null Raw SQL content, or null when the file cannot be found.
     */
    private function loadDelightSchema(string $driver): ?string
    {
        $root = dirname(__DIR__, 3);
        $dir = $root . '/composer/delight-im/auth/Database';

        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.sql');
        if ($files === false) {
            return null;
        }

        $needle = match ($driver) {
            'sqlite' => 'sqlite',
            'mysql' => 'mysql',
            default => 'post',
        };

        foreach ($files as $file) {
            if (stripos(basename($file), $needle) !== false) {
                $sql = file_get_contents($file);
                return $sql === false ? null : $sql;
            }
        }

        return null;
    }

    /**
     * Rewrites known Delight auth table names in raw SQL to include the given prefix.
     *
     * @param string $sql    Raw SQL from the Delight schema file.
     * @param string $prefix Table name prefix to prepend.
     * @return string SQL with prefixed table names.
     */
    private function applyAuthPrefix(string $sql, string $prefix): string
    {
        $tables = [
            'users',
            'users_confirmations',
            'users_remembered',
            'users_resets',
            'users_throttling',
        ];

        foreach ($tables as $table) {
            $sql = preg_replace(
                '/(?<![a-zA-Z0-9_])([`"]?)' . preg_quote($table, '/') . '([`"]?)(?![a-zA-Z0-9_])/i',
                '$1' . $prefix . $table . '$2',
                $sql
            ) ?? $sql;
        }

        return $sql;
    }

    /**
     * Executes a multi-statement SQL batch, ignoring already-exists schema errors.
     *
     * @param PDO    $db  Database connection to execute against.
     * @param string $sql Raw SQL string containing one or more semicolon-terminated statements.
     */
    private function executeSqlBatch(PDO $db, string $sql): void
    {
        $statements = preg_split('/;\s*(?:\n|$)/', $sql) ?: [];

        foreach ($statements as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            try {
                $db->exec($statement);
            } catch (\PDOException $exception) {
                if ($this->introspector->isAlreadyExistsError($exception)) {
                    continue;
                }

                throw $exception;
            }
        }
    }
}
