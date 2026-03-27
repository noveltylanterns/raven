<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;
use Raven\Lib\Auth\UserStringService;
use RuntimeException;

/**
 * Ensures auth-side schema objects and user preference columns.
 */
final class AuthSchemaBuilder
{
    private SchemaIntrospector $introspector;
    private UserStringService $userStringService;

    public function __construct(SchemaIntrospector $introspector)
    {
        $this->introspector = $introspector;
        $this->userStringService = new UserStringService();
    }

    public function ensureAuthSchema(PDO $authDb, string $driver, string $prefix): void
    {
        if (!$this->introspector->authUsersTableExists($authDb, $driver, $prefix)) {
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
        // in previously created Delight tables, so always ensure them.
        $this->ensureAuthUserPreferenceColumns($authDb, $driver, $prefix);
    }

    public function ensureInviteTokenSchema(PDO $authDb, string $driver, string $prefix): void
    {
        $table = $prefix . 'auth_invites';
        $legacyTable = $prefix . 'invite_tokens';
        $usersLegacyTable = $prefix . 'users_invites';

        if ($driver === 'sqlite') {
            $this->migrateInviteTokenSchemaSqlite($authDb, $table, [$legacyTable, $usersLegacyTable]);
            $authDb->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $table . '_hash ON ' . $table . ' (hash)');
            $authDb->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_expires ON ' . $table . ' (expires)');
            return;
        }

        if ($driver === 'mysql') {
            if ($this->tableExistsMySql($authDb, $usersLegacyTable) && !$this->tableExistsMySql($authDb, $table)) {
                $authDb->exec('RENAME TABLE ' . $usersLegacyTable . ' TO ' . $table);
            } elseif ($this->tableExistsMySql($authDb, $legacyTable) && !$this->tableExistsMySql($authDb, $table)) {
                $authDb->exec('RENAME TABLE ' . $legacyTable . ' TO ' . $table);
            }
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
            $this->renameInviteTokenColumnsMySql($authDb, $table);
            return;
        }

        if ($this->tableExistsPgSql($authDb, $usersLegacyTable) && !$this->tableExistsPgSql($authDb, $table)) {
            $authDb->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersLegacyTable) . ' RENAME TO ' . $this->introspector->quotePgIdentifier($table));
        } elseif ($this->tableExistsPgSql($authDb, $legacyTable) && !$this->tableExistsPgSql($authDb, $table)) {
            $authDb->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($legacyTable) . ' RENAME TO ' . $this->introspector->quotePgIdentifier($table));
        }
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
        $this->renameInviteTokenColumnsPgSql($authDb, $table);
        $authDb->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_expires ON ' . $table . ' (expires)');
    }

    /**
     * Adds Raven-specific profile columns to auth users table when missing.
     */
    public function ensureAuthUserPreferenceColumns(PDO $db, string $driver, string $prefix): void
    {
        $usersTable = $prefix . 'users';

        if ($driver === 'sqlite') {
            $this->migrateAuthUsersSqlite($db, $usersTable);
            if (!$this->introspector->authColumnExistsSqlite($db, $usersTable, 'string')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN string TEXT NULL');
            }
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $usersTable . '_string ON ' . $usersTable . ' (string)');
            $this->ensureAuthUserStrings($db, $usersTable);
            $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if ($driver === 'mysql') {
            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'theme')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'name')) {
                if ($this->introspector->authColumnExistsMySql($db, $usersTable, 'display_name')) {
                    $db->exec('ALTER TABLE ' . $usersTable . ' CHANGE display_name name VARCHAR(160) NULL');
                } else {
                    $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN name VARCHAR(160) NULL');
                }
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'avatar')) {
                if ($this->introspector->authColumnExistsMySql($db, $usersTable, 'avatar_path')) {
                    $db->exec('ALTER TABLE ' . $usersTable . ' CHANGE avatar_path avatar VARCHAR(255) NULL');
                } else {
                    $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN avatar VARCHAR(255) NULL');
                }
            }
            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'cover_image')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN cover_image VARCHAR(255) NULL');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'contact')) {
                if ($this->introspector->authColumnExistsMySql($db, $usersTable, 'contact_profiles')) {
                    $db->exec('ALTER TABLE ' . $usersTable . ' CHANGE contact_profiles contact TEXT NULL');
                } else {
                    $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN contact TEXT NULL');
                }
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'two_factor')) {
                if ($this->introspector->authColumnExistsMySql($db, $usersTable, 'two_factor_methods')) {
                    $db->exec('ALTER TABLE ' . $usersTable . ' CHANGE two_factor_methods two_factor LONGTEXT NULL');
                } else {
                    $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN two_factor LONGTEXT NULL');
                }
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'bio')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN bio TEXT NULL');
            }

            if (!$this->introspector->authColumnExistsMySql($db, $usersTable, 'string')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN string VARCHAR(128) NULL');
            }
            if (!$this->introspector->mySqlIndexExists($db, $usersTable, 'uniq_' . $usersTable . '_string')) {
                $db->exec('ALTER TABLE ' . $usersTable . ' ADD UNIQUE INDEX uniq_' . $usersTable . '_string (string)');
            }

            $this->ensureAuthUserStrings($db, $usersTable);
            $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
            return;
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'theme')) {
            $db->exec('ALTER TABLE ' . $usersTable . ' ADD COLUMN theme VARCHAR(50) NOT NULL DEFAULT \'default\'');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'name')) {
            if ($this->introspector->authColumnExistsPgSql($db, $usersTable, 'display_name')) {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' RENAME COLUMN display_name TO name');
            } else {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN name VARCHAR(160) NULL');
            }
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'avatar')) {
            if ($this->introspector->authColumnExistsPgSql($db, $usersTable, 'avatar_path')) {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' RENAME COLUMN avatar_path TO avatar');
            } else {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN avatar VARCHAR(255) NULL');
            }
        }
        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'cover_image')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN cover_image VARCHAR(255) NULL');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'contact')) {
            if ($this->introspector->authColumnExistsPgSql($db, $usersTable, 'contact_profiles')) {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' RENAME COLUMN contact_profiles TO contact');
            } else {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN contact TEXT NULL');
            }
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'two_factor')) {
            if ($this->introspector->authColumnExistsPgSql($db, $usersTable, 'two_factor_methods')) {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' RENAME COLUMN two_factor_methods TO two_factor');
            } else {
                $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN two_factor TEXT NULL');
            }
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'bio')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN bio TEXT NULL');
        }

        if (!$this->introspector->authColumnExistsPgSql($db, $usersTable, 'string')) {
            $db->exec('ALTER TABLE ' . $this->introspector->quotePgIdentifier($usersTable) . ' ADD COLUMN string VARCHAR(128) NULL');
        }
        if (!$this->introspector->pgSqlIndexExists($db, $usersTable, 'uniq_' . $usersTable . '_string')) {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ' . $this->introspector->quotePgIdentifier('uniq_' . $usersTable . '_string') . ' ON ' . $this->introspector->quotePgIdentifier($usersTable) . ' (string)');
        }

        $this->ensureAuthUserStrings($db, $usersTable);
        $db->exec("UPDATE " . $usersTable . " SET theme = 'default' WHERE theme IS NULL OR theme = ''");
    }

    /**
     * @param array<int, string> $legacyTables
     */
    private function migrateInviteTokenSchemaSqlite(PDO $db, string $table, array $legacyTables): void
    {
        $hasNewHash = $this->introspector->authColumnExistsSqlite($db, $table, 'hash');
        $sourceTable = null;
        $hasLegacyHash = false;
        foreach ($legacyTables as $legacyTable) {
            if ($this->introspector->authColumnExistsSqlite($db, $legacyTable, 'token_hash')) {
                $sourceTable = $legacyTable;
                $hasLegacyHash = true;
                break;
            }
            if ($this->introspector->authColumnExistsSqlite($db, $legacyTable, 'hash')) {
                $sourceTable = $legacyTable;
                break;
            }
        }

        if ($hasNewHash && !$hasLegacyHash && $sourceTable === null) {
            return;
        }
        $sourceTable = $sourceTable ?? ($hasNewHash ? $table : null);
        if ($sourceTable === null) {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
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
            return;
        }

        $tmpTable = $table . '__migrate';
        $hasValue = $this->introspector->authColumnExistsSqlite($db, $sourceTable, 'value');
        $hasTokenValue = $this->introspector->authColumnExistsSqlite($db, $sourceTable, 'token_value');

        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            foreach ($legacyTables as $legacyTable) {
                $db->exec('DROP INDEX IF EXISTS uniq_' . $legacyTable . '_token_hash');
                $db->exec('DROP INDEX IF EXISTS idx_' . $legacyTable . '_expires_at');
                $db->exec('DROP INDEX IF EXISTS uniq_' . $legacyTable . '_hash');
                $db->exec('DROP INDEX IF EXISTS idx_' . $legacyTable . '_expires');
            }
            $db->exec('DROP INDEX IF EXISTS uniq_' . $table . '_hash');
            $db->exec('DROP INDEX IF EXISTS idx_' . $table . '_expires');
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
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
            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (id, hash, value, hint, reusable, uses, expires, last_used, created, creator)
                 SELECT
                    id,
                    ' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'hash') ? 'hash' : 'token_hash') . ',
                    ' . ($hasValue ? 'value' : ($hasTokenValue ? 'token_value' : 'NULL')) . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'hint') ? 'hint' : 'token_hint') . ',
                    COALESCE(' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'reusable') ? 'reusable' : 'is_reusable') . ', 0),
                    COALESCE(' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'uses') ? 'uses' : 'use_count') . ', 0),
                    ' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'expires') ? 'expires' : 'expires_at') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'last_used') ? 'last_used' : 'last_used_at') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'created') ? 'created' : 'created_at') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $sourceTable, 'creator') ? 'creator' : 'created_by_user_id') . '
                 FROM ' . $sourceTable
            );

            if ($sourceTable !== $table) {
                $db->exec('DROP TABLE IF EXISTS ' . $table);
            }
            $db->exec('DROP TABLE ' . $sourceTable);
            foreach ($legacyTables as $legacyTable) {
                if ($legacyTable !== $sourceTable) {
                    $db->exec('DROP TABLE IF EXISTS ' . $legacyTable);
                }
            }
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $table);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function migrateAuthUsersSqlite(PDO $db, string $usersTable): void
    {
        $hasNewColumns = $this->introspector->authColumnExistsSqlite($db, $usersTable, 'name')
            && $this->introspector->authColumnExistsSqlite($db, $usersTable, 'avatar')
            && $this->introspector->authColumnExistsSqlite($db, $usersTable, 'cover_image')
            && $this->introspector->authColumnExistsSqlite($db, $usersTable, 'contact')
            && $this->introspector->authColumnExistsSqlite($db, $usersTable, 'two_factor')
            && $this->introspector->authColumnExistsSqlite($db, $usersTable, 'bio');
        $hasLegacyColumns = $this->introspector->authColumnExistsSqlite($db, $usersTable, 'display_name')
            || $this->introspector->authColumnExistsSqlite($db, $usersTable, 'avatar_path')
            || $this->introspector->authColumnExistsSqlite($db, $usersTable, 'contact_profiles')
            || $this->introspector->authColumnExistsSqlite($db, $usersTable, 'two_factor_methods');
        if ($hasNewColumns && !$hasLegacyColumns) {
            return;
        }

        $tmpTable = $usersTable . '__prefs';
        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->exec('CREATE TABLE ' . $tmpTable . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                username TEXT NULL UNIQUE,
                status INTEGER NOT NULL DEFAULT 0,
                verified INTEGER NOT NULL DEFAULT 0,
                resettable INTEGER NOT NULL DEFAULT 1,
                roles_mask INTEGER NOT NULL DEFAULT 0,
                registered INTEGER NOT NULL,
                last_login INTEGER NULL DEFAULT NULL,
                force_logout INTEGER NOT NULL DEFAULT 0,
                name TEXT NULL,
                bio TEXT NULL,
                theme TEXT NOT NULL DEFAULT \'default\',
                avatar TEXT NULL,
                cover_image TEXT NULL,
                string TEXT NULL,
                contact TEXT NULL,
                two_factor TEXT NULL
            )');
            $db->exec(
                'INSERT INTO ' . $tmpTable . ' (
                    id, email, password, username, status, verified, resettable, roles_mask, registered, last_login, force_logout,
                    name, bio, theme, avatar, cover_image, string, contact, two_factor
                 )
                 SELECT
                    id,
                    email,
                    password,
                    username,
                    status,
                    verified,
                    resettable,
                    roles_mask,
                    registered,
                    last_login,
                    force_logout,
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'name') ? 'name' : 'display_name') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'bio') ? 'bio' : 'NULL') . ',
                    COALESCE(theme, \'default\'),
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'avatar') ? 'avatar' : 'avatar_path') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'cover_image') ? 'cover_image' : 'NULL') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'string') ? 'string' : 'NULL') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'contact') ? 'contact' : 'contact_profiles') . ',
                    ' . ($this->introspector->authColumnExistsSqlite($db, $usersTable, 'two_factor') ? 'two_factor' : 'two_factor_methods') . '
                 FROM ' . $usersTable
            );
            $db->exec('DROP TABLE ' . $usersTable);
            $db->exec('ALTER TABLE ' . $tmpTable . ' RENAME TO ' . $usersTable);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->exec('DROP TABLE IF EXISTS ' . $tmpTable);
            throw $exception;
        }
    }

    private function ensureAuthUserStrings(PDO $db, string $usersTable): void
    {
        $select = $db->prepare(
            'SELECT id
             FROM ' . $usersTable . '
             WHERE string IS NULL OR TRIM(COALESCE(string, \'\')) = \'\'
             ORDER BY id ASC'
        );
        $select->execute();
        $rows = $select->fetchAll() ?: [];
        if ($rows === []) {
            return;
        }

        $exists = $db->prepare(
            'SELECT 1
             FROM ' . $usersTable . '
             WHERE string = :string
             LIMIT 1'
        );
        $update = $db->prepare(
            'UPDATE ' . $usersTable . '
             SET string = :string
             WHERE id = :id'
        );

        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }

            $value = $this->userStringService->generateUnique(
                28,
                function (string $candidate) use ($exists): bool {
                    $exists->execute([':string' => $candidate]);
                    return $exists->fetchColumn() !== false;
                }
            );
            $update->execute([
                ':id' => $userId,
                ':string' => $value,
            ]);
        }
    }

    private function renameInviteTokenColumnsMySql(PDO $db, string $table): void
    {
        $renames = [
            'token_hash' => ['hash', 'CHAR(64) NOT NULL'],
            'token_value' => ['value', 'VARCHAR(191) NULL'],
            'token_hint' => ['hint', 'VARCHAR(16) NOT NULL'],
            'is_reusable' => ['reusable', 'TINYINT(1) NOT NULL DEFAULT 0'],
            'use_count' => ['uses', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            'expires_at' => ['expires', 'BIGINT UNSIGNED NULL'],
            'last_used_at' => ['last_used', 'BIGINT UNSIGNED NULL'],
            'created_at' => ['created', 'DATETIME NOT NULL'],
            'created_by_user_id' => ['creator', 'BIGINT UNSIGNED NULL'],
        ];

        foreach ($renames as $old => [$new, $definition]) {
            if ($this->introspector->authColumnExistsMySql($db, $table, $old) && !$this->introspector->authColumnExistsMySql($db, $table, $new)) {
                $db->exec('ALTER TABLE ' . $table . ' CHANGE ' . $old . ' ' . $new . ' ' . $definition);
            }
        }
    }

    private function renameInviteTokenColumnsPgSql(PDO $db, string $table): void
    {
        $quoted = $this->introspector->quotePgIdentifier($table);
        $renames = [
            'token_hash' => 'hash',
            'token_value' => 'value',
            'token_hint' => 'hint',
            'is_reusable' => 'reusable',
            'use_count' => 'uses',
            'expires_at' => 'expires',
            'last_used_at' => 'last_used',
            'created_at' => 'created',
            'created_by_user_id' => 'creator',
        ];

        foreach ($renames as $old => $new) {
            if ($this->introspector->authColumnExistsPgSql($db, $table, $old) && !$this->introspector->authColumnExistsPgSql($db, $table, $new)) {
                $db->exec('ALTER TABLE ' . $quoted . ' RENAME COLUMN ' . $old . ' TO ' . $new);
            }
        }
    }

    private function tableExistsMySql(PDO $db, string $table): bool
    {
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

    private function tableExistsPgSql(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute([':table_name' => $table]);

        return $stmt->fetchColumn() !== null;
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
