<?php

/**
 * RAVEN CMS
 * ~/private/lib/Database/MysqlConfig.php
 * MySQL-specific database config extraction and DSN helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Database;

/**
 * Normalizes MySQL-specific config and exposes connection primitives.
 */
final class MysqlConfig
{
    /** @var array<string, mixed> */
    private array $settings;

    /**
     * Builds a MySQL config helper from the Raven database config payload.
     *
     * @param array<string, mixed> $config Raven database configuration array.
     * @return self MySQL helper backed by the `mysql` config section.
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $settings */
        $settings = (array) ($config['mysql'] ?? []);
        return new self($settings);
    }

    /**
     * Stores a normalized MySQL config section.
     *
     * @param array<string, mixed> $settings MySQL connection settings.
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Returns the PDO DSN string for MySQL.
     *
     * @return string MySQL PDO DSN.
     */
    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($this->settings['host'] ?? '127.0.0.1'),
            (int) ($this->settings['port'] ?? 3306),
            (string) ($this->settings['name'] ?? 'raven'),
            (string) ($this->settings['charset'] ?? 'utf8mb4')
        );
    }

    /**
     * Returns the configured MySQL username.
     *
     * @return string MySQL username, or empty string when not configured.
     */
    public function username(): string
    {
        return (string) ($this->settings['user'] ?? '');
    }

    /**
     * Returns the configured MySQL password.
     *
     * @return string MySQL password, or empty string when not configured.
     */
    public function password(): string
    {
        return (string) ($this->settings['pass'] ?? '');
    }
}

