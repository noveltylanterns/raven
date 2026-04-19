<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/schema.php
 * Signup Sheets extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures signup extension storage: flat-file form definitions and submissions table.
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
    $tableResolver = is_callable($context['table'] ?? null) ? $context['table'] : static fn (?string $t = null): string => (string) $t;
    $submissionsTable = $tableResolver();
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

    // Ensure forms flat-file exists.
    if (!is_file($dataFile)) {
        $directory = dirname($dataFile);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to prepare signup data directory.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n";
        if (@file_put_contents($dataFile, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write signup forms data.');
        }

        // Flush opcode cache so any same-request require() picks up the new file.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dataFile, true);
        }
    }

    // Ensure submissions table exists.
    if (!$tableExists($db, $driver, $submissionsTable)) {
        if ($driver === 'sqlite') {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $submissionsTable . ' (
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
                created TEXT NOT NULL
            )');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $submissionsTable . '_form_slug_email ON ' . $submissionsTable . ' (form_slug, email)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $submissionsTable . '_form_slug_created ON ' . $submissionsTable . ' (form_slug, created DESC)');
        } elseif ($driver === 'mysql') {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $submissionsTable . ' (
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
                created DATETIME NOT NULL,
                UNIQUE KEY uniq_' . $submissionsTable . '_form_slug_email (form_slug, email),
                INDEX idx_' . $submissionsTable . '_form_slug_created (form_slug, created)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $submissionsTable . ' (
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
                created TIMESTAMP NOT NULL
            )');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $submissionsTable . '_form_slug_email ON ' . $submissionsTable . ' (form_slug, email)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $submissionsTable . '_form_slug_created ON ' . $submissionsTable . ' (form_slug, created DESC)');
        }
    }

    // Ensure optional columns added in later versions exist.
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
