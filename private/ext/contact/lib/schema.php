<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/lib/schema.php
 * Contact extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures contact extension storage.
 *
 * @param array<string, mixed> $context
 */
return static function (array $context): void {
    if (
        !isset($context['db'], $context['driver'], $context['storage'])
        || !$context['db'] instanceof \PDO
    ) {
        return;
    }

    $db = $context['db'];
    $driver = (string) $context['driver'];
    $tableResolver = is_callable($context['table'] ?? null) ? $context['table'] : static fn (?string $legacyTable = null): string => (string) $legacyTable;
    $legacyTableResolver = is_callable($context['legacy_table'] ?? null) ? $context['legacy_table'] : $tableResolver;
    $formsTable = $legacyTableResolver('ext_contact');
    $legacySubmissionsTable = $legacyTableResolver('ext_contact_submissions');
    $submissionsTable = $legacyTableResolver('contact');
    $storage = is_array($context['storage']) ? $context['storage'] : [];
    $localRoot = rtrim((string) ($storage['local'] ?? ''), '/');
    if ($localRoot === '') {
        return;
    }
    $dataFile = $localRoot . '/forms.php';

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
            throw new \RuntimeException('Failed to prepare contact data directory.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(array_values($forms), true) . ";\n";
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write contact forms data.');
        }
    };

    $createSubmissionsTable = static function (\PDO $pdo, string $dbDriver, string $table): void {
        if ($dbDriver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_slug TEXT NOT NULL,
                sender_name TEXT NOT NULL,
                sender_email TEXT NOT NULL,
                message_text TEXT NOT NULL,
                additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
                source_url TEXT NOT NULL DEFAULT \'\',
                ip_address TEXT NULL,
                hostname TEXT NULL,
                user_agent TEXT NULL,
                created TEXT NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_form_slug_created ON ' . $table . ' (form_slug, created DESC)');
            return;
        }

        if ($dbDriver === 'mysql') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_slug VARCHAR(160) NOT NULL,
                sender_name VARCHAR(255) NOT NULL,
                sender_email VARCHAR(254) NOT NULL,
                message_text MEDIUMTEXT NOT NULL,
                additional_fields_json TEXT NOT NULL,
                source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
                ip_address VARCHAR(45) NULL,
                hostname VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                created DATETIME NOT NULL,
                INDEX idx_' . $table . '_form_slug_created (form_slug, created)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (
            id BIGSERIAL PRIMARY KEY,
            form_slug VARCHAR(160) NOT NULL,
            sender_name VARCHAR(255) NOT NULL,
            sender_email VARCHAR(254) NOT NULL,
            message_text TEXT NOT NULL,
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            source_url VARCHAR(2048) NOT NULL DEFAULT \'\',
            ip_address VARCHAR(45) NULL,
            hostname VARCHAR(255) NULL,
            user_agent VARCHAR(500) NULL,
            created TIMESTAMP NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_' . $table . '_form_slug_created ON ' . $table . ' (form_slug, created DESC)');
    };

    if ($tableExists($db, $driver, $formsTable) && !$columnExists($db, $driver, $formsTable, 'form_slug')) {
        $stmt = $db->prepare(
            'SELECT name, slug, enabled, save_mail_locally, destination, cc, bcc, additional_fields_json
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
                id, form_slug, sender_name, sender_email, message_text, additional_fields_json,
                source_url, ip_address, hostname, user_agent, created
            )
            SELECT
                id, form_slug, sender_name, sender_email, message_text, additional_fields_json,
                source_url, ip_address, hostname, user_agent, ' . ($columnExists($db, $driver, $formsTable, 'created') ? 'created' : 'created_at') . '
            FROM ' . $formsTable
        );
        $db->exec('DROP TABLE IF EXISTS ' . $formsTable);
    }

    if ($legacyExists) {
        $db->exec(
            'INSERT INTO ' . $submissionsTable . ' (
                id, form_slug, sender_name, sender_email, message_text, additional_fields_json,
                source_url, ip_address, hostname, user_agent, created
            )
            SELECT
                id, form_slug, sender_name, sender_email, message_text, additional_fields_json,
                source_url, ip_address, hostname, user_agent, ' . ($columnExists($db, $driver, $legacySubmissionsTable, 'created') ? 'created' : 'created_at') . '
            FROM ' . $legacySubmissionsTable
        );
        $db->exec('DROP TABLE IF EXISTS ' . $legacySubmissionsTable);
    }

    if ($columnExists($db, $driver, $submissionsTable, 'created_at') && !$columnExists($db, $driver, $submissionsTable, 'created')) {
        if ($driver === 'sqlite') {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' RENAME COLUMN created_at TO created');
            $db->exec('DROP INDEX IF EXISTS idx_' . $submissionsTable . '_form_slug_created_at');
        } elseif ($driver === 'mysql') {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' CHANGE created_at created DATETIME NOT NULL');
            $db->exec('DROP INDEX idx_' . $submissionsTable . '_form_slug_created_at ON ' . $submissionsTable);
        } else {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' RENAME COLUMN created_at TO created');
            $db->exec('DROP INDEX IF EXISTS idx_' . $submissionsTable . '_form_slug_created_at');
        }
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
    $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $submissionsTable . '_form_slug_created ON ' . $submissionsTable . ' (form_slug, created DESC)');
};
