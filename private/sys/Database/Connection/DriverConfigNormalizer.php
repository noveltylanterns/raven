<?php

declare(strict_types=1);

namespace Raven\Core\Database\Connection;

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
        $prefix = (string) ($config['prefix'] ?? '');
        return preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function mysql(array $config): array
    {
        return $this->section($config, 'mysql');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function pgsql(array $config): array
    {
        return $this->section($config, 'pgsql');
    }

    /**
     * @param array<string, mixed> $config
     */
    public function sqliteBasePath(array $config): string
    {
        $sqlite = $this->section($config, 'sqlite');
        $basePath = rtrim((string) ($sqlite['path'] ?? ''), '/');
        if ($basePath === '') {
            throw new RuntimeException('Missing SQLite base path configuration.');
        }

        return $basePath;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function section(array $config, string $key): array
    {
        /** @var array<string, mixed> $section */
        $section = (array) ($config[$key] ?? []);
        return $section;
    }
}
