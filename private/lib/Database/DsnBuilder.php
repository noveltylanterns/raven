<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/DsnBuilder.php
 * Builds backend DSN strings from normalized driver config payloads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Builds backend DSN strings from normalized driver config payloads.
 */
final class DsnBuilder
{
    /**
     * Returns a MySQL DSN string from a normalized MySQL config section.
     *
     * @param array<string, mixed> $mysql MySQL connection parameters (host, port, name, charset).
     * @return string PDO DSN string for the MySQL driver.
     */
    public function mysql(array $mysql): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($mysql['host'] ?? '127.0.0.1'),
            (int) ($mysql['port'] ?? 3306),
            (string) ($mysql['name'] ?? 'raven'),
            (string) ($mysql['charset'] ?? 'utf8mb4')
        );
    }

    /**
     * Returns a PostgreSQL DSN string from a normalized PostgreSQL config section.
     *
     * @param array<string, mixed> $pgsql PostgreSQL connection parameters (host, port, name).
     * @return string PDO DSN string for the pgsql driver.
     */
    public function pgsql(array $pgsql): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($pgsql['host'] ?? '127.0.0.1'),
            (int) ($pgsql['port'] ?? 5432),
            (string) ($pgsql['name'] ?? 'raven')
        );
    }
}
