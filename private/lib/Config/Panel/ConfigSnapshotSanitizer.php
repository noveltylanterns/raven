<?php

declare(strict_types=1);

namespace Raven\Lib\Config\Panel;

/**
 * Applies normalized config-snapshot cleanup rules for panel config workflows.
 */
final class ConfigSnapshotSanitizer
{
    /**
     * Removes SQLite file map from user-managed config payload.
     *
     * SQLite filenames are core-managed and intentionally not stored in
     * `private/dat/config.php` to prevent drift across installs.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function removeSqliteDatabaseFiles(array $config): array
    {
        $database = $config['database'] ?? null;
        if (!is_array($database)) {
            return $config;
        }

        $sqlite = $database['sqlite'] ?? null;
        if (!is_array($sqlite)) {
            return $config;
        }

        unset($sqlite['files']);
        $database['sqlite'] = $sqlite;
        $config['database'] = $database;

        return $config;
    }
}
