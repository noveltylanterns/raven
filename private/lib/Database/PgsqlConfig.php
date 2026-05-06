<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/PgsqlConfig.php
 * PostgreSQL-specific database config extraction and DSN helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Normalizes PostgreSQL-specific config and exposes connection primitives.
 */
final class PgsqlConfig
{
    /** @var array<string, mixed> */
    private array $settings;

    /**
     * Builds a PostgreSQL config helper from the Raven database config payload.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @return self PostgreSQL helper backed by the `pgsql` config section.
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $settings */
        $settings = (array) ($config['pgsql'] ?? []);
        return new self($settings);
    }

    /**
     * Stores a normalized PostgreSQL config section.
     *
     * @param array<string, mixed> $settings PostgreSQL connection settings.
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Returns the PDO DSN string for PostgreSQL.
     *
     * @return string PostgreSQL PDO DSN.
     */
    public function dsn(): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($this->settings['host'] ?? '127.0.0.1'),
            (int) ($this->settings['port'] ?? 5432),
            (string) ($this->settings['name'] ?? 'raven')
        );
    }

    /**
     * Returns the configured PostgreSQL username.
     *
     * @return string PostgreSQL username, or empty string when not configured.
     */
    public function username(): string
    {
        return (string) ($this->settings['user'] ?? '');
    }

    /**
     * Returns the configured PostgreSQL password.
     *
     * @return string PostgreSQL password, or empty string when not configured.
     */
    public function password(): string
    {
        return (string) ($this->settings['pass'] ?? '');
    }
}

