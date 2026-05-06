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

}
