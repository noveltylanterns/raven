<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Connection;

use RuntimeException;

/**
 * Normalizes backend driver selection and per-driver config payloads.
 */
final class DriverConfigNormalizer
{
    /**
     * @param array<string, mixed> $config
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
     * @param array<string, mixed> $config
     */
    public function prefix(array $config): string
    {
        $prefix = (string) ($config['table_prefix'] ?? '');
        return preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function mysql(array $config): array
    {
        /** @var array<string, mixed> $mysql */
        $mysql = (array) ($config['mysql'] ?? []);
        return $mysql;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function pgsql(array $config): array
    {
        /** @var array<string, mixed> $pgsql */
        $pgsql = (array) ($config['pgsql'] ?? []);
        return $pgsql;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function sqliteBasePath(array $config): string
    {
        /** @var array<string, mixed> $sqlite */
        $sqlite = (array) ($config['sqlite'] ?? []);
        $basePath = rtrim((string) ($sqlite['base_path'] ?? ''), '/');
        if ($basePath === '') {
            throw new RuntimeException('Missing SQLite base path configuration.');
        }

        return $basePath;
    }
}

