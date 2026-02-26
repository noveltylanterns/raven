<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/schema.php
 * Signup Sheets extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures signup extension tables and shortcode registry rows.
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
    $submissionsTable = $tableResolver('ext_signups_submissions');
    $shortcodesTable = $tableResolver('shortcodes');
    $now = gmdate('Y-m-d H:i:s');
    $safeNow = str_replace("'", "''", $now);

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

        $stmt = $pdo->prepare(
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
    };

    if ($driver === 'sqlite') {
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $formsTable . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 1,
            additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

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
            created_at TEXT NOT NULL
        )');

        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS extensions.uniq_ext_signups_slug ON ext_signups (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS extensions.idx_ext_signups_submissions_form_slug_created_at ON ext_signups_submissions (form_slug, created_at DESC)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS extensions.uniq_ext_signups_submissions_form_slug_email ON ext_signups_submissions (form_slug, email)');

        if (!$columnExists($db, $driver, $submissionsTable, 'additional_fields_json')) {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL DEFAULT \'[]\'');
        }
        if (!$columnExists($db, $driver, $submissionsTable, 'hostname')) {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN hostname TEXT NULL');
        }

        $db->exec('UPDATE ' . $submissionsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
        $db->exec("UPDATE " . $submissionsTable . " SET hostname = NULL WHERE hostname = ''");

        $db->exec(
            "INSERT OR IGNORE INTO {$shortcodesTable} (extension_name, label, shortcode, sort_order, created_at, updated_at)
             SELECT
                 'signups' AS extension_name,
                 'Signup Sheets: ' || s.name AS label,
                 '[signups slug=\"' || s.slug || '\"]' AS shortcode,
                 s.id AS sort_order,
                 COALESCE(s.created_at, '{$safeNow}') AS created_at,
                 COALESCE(s.updated_at, '{$safeNow}') AS updated_at
             FROM {$formsTable} s
             WHERE s.enabled = 1"
        );

        return;
    }

    if ($driver === 'mysql') {
        $db->exec('CREATE TABLE IF NOT EXISTS ' . $formsTable . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            additional_fields_json TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_' . $formsTable . '_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

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
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_' . $submissionsTable . '_form_slug_email (form_slug, email),
            INDEX idx_' . $submissionsTable . '_form_slug_created_at (form_slug, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        if (!$columnExists($db, $driver, $submissionsTable, 'additional_fields_json')) {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL');
        }
        if (!$columnExists($db, $driver, $submissionsTable, 'hostname')) {
            $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN hostname VARCHAR(255) NULL');
        }

        $db->exec('UPDATE ' . $submissionsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
        $db->exec("UPDATE " . $submissionsTable . " SET hostname = NULL WHERE hostname = ''");

        $db->exec(
            "INSERT IGNORE INTO {$shortcodesTable} (extension_name, label, shortcode, sort_order, created_at, updated_at)
             SELECT
                 'signups' AS extension_name,
                 CONCAT('Signup Sheets: ', s.name) AS label,
                 CONCAT('[signups slug=\"', s.slug, '\"]') AS shortcode,
                 s.id AS sort_order,
                 COALESCE(s.created_at, '{$safeNow}') AS created_at,
                 COALESCE(s.updated_at, '{$safeNow}') AS updated_at
             FROM {$formsTable} s
             WHERE s.enabled = 1"
        );

        return;
    }

    $db->exec('CREATE TABLE IF NOT EXISTS ' . $formsTable . ' (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(160) NOT NULL,
        enabled SMALLINT NOT NULL DEFAULT 1,
        additional_fields_json TEXT NOT NULL DEFAULT \'[]\',
        created_at TIMESTAMP NOT NULL,
        updated_at TIMESTAMP NOT NULL
    )');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $formsTable . '_slug ON ' . $formsTable . ' (slug)');

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
        created_at TIMESTAMP NOT NULL
    )');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_' . $submissionsTable . '_form_slug_email ON ' . $submissionsTable . ' (form_slug, email)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_' . $submissionsTable . '_form_slug_created_at ON ' . $submissionsTable . ' (form_slug, created_at DESC)');

    if (!$columnExists($db, $driver, $submissionsTable, 'additional_fields_json')) {
        $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN additional_fields_json TEXT NOT NULL DEFAULT \'[]\'');
    }
    if (!$columnExists($db, $driver, $submissionsTable, 'hostname')) {
        $db->exec('ALTER TABLE ' . $submissionsTable . ' ADD COLUMN hostname VARCHAR(255) NULL');
    }

    $db->exec('UPDATE ' . $submissionsTable . ' SET additional_fields_json = \'[]\' WHERE additional_fields_json IS NULL OR additional_fields_json = \'\'');
    $db->exec("UPDATE " . $submissionsTable . " SET hostname = NULL WHERE hostname = ''");

    $db->exec(
        "INSERT INTO {$shortcodesTable} (extension_name, label, shortcode, sort_order, created_at, updated_at)
         SELECT
             'signups' AS extension_name,
             'Signup Sheets: ' || s.name AS label,
             '[signups slug=\"' || s.slug || '\"]' AS shortcode,
             s.id AS sort_order,
             COALESCE(s.created_at, '{$safeNow}') AS created_at,
             COALESCE(s.updated_at, '{$safeNow}') AS updated_at
         FROM {$formsTable} s
         WHERE s.enabled = 1
         ON CONFLICT (extension_name, shortcode) DO NOTHING"
    );
};
