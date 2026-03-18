<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;
use RuntimeException;

/**
 * Ensures auth-side schema objects and user preference columns.
 */
final class AuthSchemaBuilder
{
    private SchemaIntrospector $introspector;

    public function __construct(SchemaIntrospector $introspector)
    {
        $this->introspector = $introspector;
    }

    public function ensureAuthSchema(PDO $authDb, string $driver, string $prefix): void
    {
        if (!$this->introspector->authUsersTableExists($authDb, $driver, $prefix)) {
            $schema = $this->loadDelightSchema($driver);

            if ($schema === null) {
                throw new RuntimeException('Delight Auth SQL schema files are missing. Install composer dependencies before bootstrap.');
            }

            // Prefix auth tables in shared-DB modes for namespace isolation.
            if ($driver !== 'sqlite' && $prefix !== '') {
                $schema = $this->applyAuthPrefix($schema, $prefix);
            }

            $this->executeSqlBatch($authDb, $schema);
        }

        // Profile columns are required by User Preferences and may be missing
        // in previously created Delight tables, so always ensure them.
        $this->ensureAuthUserPreferenceColumns($authDb, $driver, $driver === 'sqlite' ? '' : $prefix);
    }

    public function ensureInviteTokenSchema(PDO $authDb, string $driver, string $prefix): void
    {
        $table = $driver === 'sqlite' ? 'invite_tokens' : ($prefix . 'invite_tokens');

        if ($driver === 'sqlite') {
            $authDb->exec('CREATE TABLE IF NOT EXISTS invite_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                token_value TEXT NULL,
                token_hint TEXT NOT NULL,
                is_reusable INTEGER NOT NULL DEFAULT 0,
                use_count INTEGER NOT NULL DEFAULT 0,
                expires_at INTEGER NULL,
                last_used_at INTEGER NULL,
                created_at TEXT NOT NULL,
                created_by_user_id INTEGER NULL
            )');
            if (!$this->introspector->authColumnExistsSqlite($authDb, 'invite_tokens', 'token_value')) {
                $authDb->exec('ALTER TABLE invite_tokens ADD COLUMN token_value TEXT NULL');
            }
            $authDb->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_invite_tokens_token_hash ON invite_tokens (token_hash)');
            $authDb->exec('CREATE INDEX IF NOT EXISTS idx_invite_tokens_expires_at ON invite_tokens (expires_at)');
            return;
        }

        if ($driver === 'mysql') {
            $authDb->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                token_value VARCHAR(191) NULL,
                token_hint VARCHAR(16) NOT NULL,
                is_reusable TINYINT(1) NOT NULL DEFAULT 0,
                use_count INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at BIGINT UNSIGNED NULL,
                last_used_at BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                UNIQUE KEY (token_hash),
                INDEX (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            if (!$this->introspector->authColumnExistsMySql($authDb, $table, 'token_value')) {
                $authDb->exec('ALTER TABLE ' . $table . ' ADD COLUMN token_value VARCHAR(191) NULL AFTER token_hash');
            }
            return;
        }

        $authDb->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
            id BIGSERIAL PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            token_value VARCHAR(191) NULL,
            token_hint VARCHAR(16) NOT NULL,
            is_reusable SMALLINT NOT NULL DEFAULT 0,
            use_count INTEGER NOT NULL DEFAULT 0,
            expires_at BIGINT NULL,
            last_used_at BIGINT NULL,
            created_at TIMESTAMP NOT NULL,
            created_by_user_id BIGINT NULL
        )');
        if (!$this->introspector->authColumnExistsPgSql($authDb, $table, 'token_value')) {
            $authDb->exec('ALTER TABLE ' . $table . ' ADD COLUMN token_value VARCHAR(191) NULL');
        }
        $authDb->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_expires_at ON ' . $table . ' (expires_at)');
    }

    /**
     * Adds Raven-specific profile columns to auth users table when missing.
     */
    public function ensureAuthUserPreferenceColumns(PDO $db, string $driver, string $prefix): void
    {
        $usersTable = $driver === 'sqlite' ? 'users' : $prefix . 'users';

        if ($driver === 'sqlite') {
            if (!$this->introspector->authColumnExistsSqlite($db, $usersTable, 'display_name')) {
                $db->exec('ALTER TABLE users ADD COLUMN display_name TEXT NULL');
            }

            if (!$this->introspector->authColumnExistsSqlite($db, $usersTable, 'theme')) {
                $db->exec('ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT \'default\'');
            }

            if (!$this->introspector->authColumnExistsSqlite($db, $usersTable, 'avatar_path')) {
                $db->exec('ALTER TABLE users ADD COLUMN avatar_path TEXT NULL');
            }

            if (!$this->introspector->authColumnExistsSqlite($db, $usersTable, 'contact_profiles')) {
                $db->exec('ALTER TABLE users ADD COLUMN contact_profiles TEXT NULL');
            }

            if (!$this->introspector->authColumnExistsSqlite($db, $usersTable, 'two_factor_methods')) {
                $db->exec('ALTER TABLE users ADD COLUMN two_factor_methods TEXT NULL');
            }

            $db->exec("UPDATE users SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'display_name')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN display_name VARCHAR(160) NULL');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'theme')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'avatar_path')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar_path VARCHAR(255) NULL');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'contact_profiles')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact_profiles TEXT NULL');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'two_factor_methods')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor_methods LONGTEXT NULL');
            }

            $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'display_name')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN display_name VARCHAR(160) NULL');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'theme')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'avatar_path')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar_path VARCHAR(255) NULL');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'contact_profiles')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact_profiles TEXT NULL');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'two_factor_methods')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor_methods TEXT NULL');
        }

        $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
    }

    private function loadDelightSchema(string $driver): ?string
    {
        $root = dirname(__DIR__, 4);
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
                if ($this->introspector->isAlreadyExistsSchemaError($exception)) {
                    continue;
                }

                throw $exception;
            }
        }
    }
}
