<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/DriverConfigNormalizer.php
 * Normalizes backend driver selection and per-driver config payloads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

use RuntimeException;

/**
 * Normalizes backend driver selection and per-driver config payloads.
 */
final class DriverConfigNormalizer
{
    /**
     * Returns the canonical driver slug from the database config array.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @return string Canonical driver slug: sqlite, mysql, or pgsql.
     * @throws RuntimeException When the configured driver is not supported.
     */
    public function driver(array $config): string
    {
        $driver = strtolower((string) ($config['driver'] ?? 'sqlite'));
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        return $driver;
    }

    /**
     * Returns the sanitized table name prefix from the database config array.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @return string Table prefix string, empty when not configured.
     */
    public function prefix(array $config): string
    {
        $prefix = (string) ($config['prefix'] ?? '');
        return preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns the MySQL-specific config section from the database config array.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @return array<string, mixed> MySQL connection parameters.
     */
    public function mysql(array $config): array
    {
        return $this->section($config, 'mysql');
    }

    /**
     * Returns the PostgreSQL-specific config section from the database config array.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @return array<string, mixed> PostgreSQL connection parameters.
     */
    public function pgsql(array $config): array
    {
        return $this->section($config, 'pgsql');
    }

    /**
     * Extracts a named subsection from the config array, defaulting to an empty array.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @param string               $key    Section key to extract (e.g., 'mysql', 'pgsql').
     * @return array<string, mixed> Config sub-array for the named driver or section.
     */
    private function section(array $config, string $key): array
    {
        /** @var array<string, mixed> $section */
        $section = (array) ($config[$key] ?? []);
        return $section;
    }
}
