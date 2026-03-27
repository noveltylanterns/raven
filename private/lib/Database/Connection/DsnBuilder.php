<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Connection;

/**
 * Builds backend DSN strings from normalized driver config payloads.
 */
final class DsnBuilder
{
    /**
     * @param array<string, mixed> $mysql
     */
    public function mysql(array $mysql): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($mysql['host'] ?? '127.0.0.1'),
            (int) ($mysql['port'] ?? 3306),
            (string) ($mysql['name'] ?? ($mysql['dbname'] ?? 'raven')),
            (string) ($mysql['charset'] ?? 'utf8mb4')
        );
    }

    /**
     * @param array<string, mixed> $pgsql
     */
    public function pgsql(array $pgsql): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($pgsql['host'] ?? '127.0.0.1'),
            (int) ($pgsql['port'] ?? 5432),
            (string) ($pgsql['name'] ?? ($pgsql['dbname'] ?? 'raven'))
        );
    }
}
