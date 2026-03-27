<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/lib/schema.php
 * Signup Sheets extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures signup extension storage.
 *
 * @param array<string, mixed> $context
 */
return static function (array $context): void {
    if (
        !isset($context['db'], $context['driver'], $context['table'])
        || !$context['db'] instanceof \PDO
        || !is_callable($context['table'])
    ) {
        return;
    }

    $db = $context['db'];
    $driver = (string) $context['driver'];
    $tableResolver = $context['table'];
    $formsTable = $tableResolver('ext_signups');
    $legacySubmissionsTable = $tableResolver('ext_signups_submissions');
    $submissionsTable = $tableResolver('signups');
    $dataFile = dirname(__DIR__, 4) . '/private/dat/ext/signups/forms.php';

    $tableExists = static function (\PDO $pdo, string $dbDriver, string $table): bool {
        if ($dbDriver === 'sqlite') {
            $schema = null;
            $tableName = $table;
            if (str_contains($table, '.')) {
                [$schemaPart, $tablePart] = explode('.', $table, 2);
                $schema = trim($schemaPart);
                $tableName = trim($tablePart);
            }

            $query = $schema === null
                ? 'SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = :table LIMIT 1'
                : 'SELECT 1 FROM ' . $schema . '.sqlite_master WHERE type = \'table\' AND name = :table LIMIT 1';
            $stmt = $pdo->prepare($query);
            $stmt->execute([':table' => $tableName]);

            return $stmt->fetchColumn() !== false;
        }

        if ($dbDriver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                 LIMIT 1'
            );
            $stmt->execute([':table_name' => $table]);

            return $stmt->fetchColumn() !== false;
        }

        $tableName = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = current_schema()
               AND table_name = :table_name
             LIMIT 1'
        );
        $stmt->execute([':table_name' => $tableName]);

        return $stmt->fetchColumn() !== false;
    };

    $columnExists = static function (\PDO $pdo, string $dbDriver, string $table, string $column): bool {
        if ($dbDriver === 'sqlite') {
            $schema = null;
            $tableName = $table;
            if (str_contains($table, '.')) {
                [$schemaPart, $tablePart] = explode('.', $table, 2);
                $schema = trim($schemaPart);
                $tableName = trim($tablePart);
            }

            $pragma = $schema === null
                ? 'PRAGMA table_info(' . $tableName . ')'
                : 'PRAGMA ' . $schema . '.table_info(' . $tableName . ')';
            $stmt = $pdo->query($pragma);
            if ($stmt === false) {
                return false;
            }

            foreach ($stmt->fetchAll() ?: [] as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        if ($dbDriver === 'mysql') {
            $stmt = $pdo->prepare(
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

        $tableName = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $column,
        ]);

        return $stmt->fetchColumn() !== false;
    };

    $writeForms = static function (string $path, array $forms): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to prepare signup data directory.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(array_values($forms), true) . ";\n";
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write signup forms data.');
        }
    };

    $createSubmissionsTable = static function (\PDO $pdo, string $dbDriver, string $table): void {
        if ($dbDriver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_slug TEXT NOT NULL,
                email TEXT NOT NULL,
                display_name TEXT NOT NULL,
                country TEXT NOT NULL,
                additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
                source_url TEXT NOT NULL DEFAULT \'\',
                ip_address TEXT NULL,
                hostname TEXT NULL,
                user_agent TEXT NULL,
                created_at TEXT NOT NULL
            )');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $table . '_form_slug_email ON ' . $table . ' (form_slug, email)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_form_slug_created_at ON ' . $table . ' (form_slug, created_at DESC)');
            return;
        }

        if ($dbDriver === 'mysql') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_slug VARCHAR(160) NOT NULL,
                email VARCHAR(254) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                country VARCHAR(16) NOT NULL,
                additional_fields_json TEXT NOT NULL,
                source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
                ip_address VARCHAR(45) NULL,
                hostname VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $table . '_form_slug_email (form_slug, email),
                INDEX idx_' . $table . '_form_slug_created_at (form_slug, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
            id BIGSERIAL PRIMARY KEY,
            form_slug VARCHAR(160) NOT NULL,
            email VARCHAR(254) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            country VARCHAR(16) NOT NULL,
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
            ip_address VARCHAR(45) NULL,
            hostname VARCHAR(255) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $table . '_form_slug_email ON ' . $table . ' (form_slug, email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_form_slug_created_at ON ' . $table . ' (form_slug, created_at DESC)');
    };

    if ($tableExists($db, $driver, $formsTable) && !$columnExists($db, $driver, $formsTable, 'form_slug')) {
        $stmt = $db->prepare(
            'SELECT name, slug, enabled, additional_fields_json
             FROM ' . $formsTable . '
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute();
        $writeForms($dataFile, $stmt->fetchAll() ?: []);
        $db->exec('DROP TABLE IF EXISTS ' . $formsTable);
    } elseif (!is_file($dataFile)) {
        $writeForms($dataFile, []);
    }

    $legacyExists = $tableExists($db, $driver, $legacySubmissionsTable);
    if (!$tableExists($db, $driver, $submissionsTable)) {
        $createSubmissionsTable($db, $driver, $submissionsTable);
    }

    $legacyInlineSubmissions = $formsTable !== $submissionsTable
        && $tableExists($db, $driver, $formsTable)
        && $columnExists($db, $driver, $formsTable, 'form_slug');
    if ($legacyInlineSubmissions) {
        $db->exec(
            'INSERT INTO ' . $submissionsTable . ' (
                id, form_slug, email, display_name, country, additional_fields_json,
                source_url, ip_address, hostname, user_agent, created_at
            )
            SELECT
                id, form_slug, email, display_name, country, additional_fields_json,
                source_url, ip_address, hostname, user_agent, created_at
            FROM ' . $formsTable
        );
        $db->exec('DROP TABLE IF EXISTS ' . $formsTable);
    }

    if ($legacyExists) {
        $db->exec(
            'INSERT INTO ' . $submissionsTable . ' (
                id, form_slug, email, display_name, country, additional_fields_json,
                source_url, ip_address, hostname, user_agent, created_at
            )
            SELECT
                id, form_slug, email, display_name, country, additional_fields_json,
                source_url, ip_address, hostname, user_agent, created_at
            FROM ' . $legacySubmissionsTable
        );
        $db->exec('DROP TABLE IF EXISTS ' . $legacySubmissionsTable);
    }

    if (!$columnExists($db, $driver, $submissionsTable, 'additional_fields_json')) {
        if ($driver === 'sqlite') {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL DEFAULT \'[]\'');
        } elseif ($driver === 'mysql') {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL');
        } else {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL DEFAULT \'[]\'');
        }
    }
    if (!$columnExists($db, $driver, $submissionsTable, 'hostname')) {
        if ($driver === 'sqlite') {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN hostname TEXT NULL');
        } else {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN hostname VARCHAR(255) NULL');
        }
    }

    $db->exec('UPDATE ' . $submissionsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
    $db->exec("UPDATE " . $submissionsTable . " SET hostname = NULL WHERE hostname = ''");
};
