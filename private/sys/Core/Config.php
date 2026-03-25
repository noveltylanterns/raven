<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Config.php
 * Core framework component used by Raven CMS.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Core utilities are shared by both public and panel runtime flows.

declare(strict_types=1);

namespace Raven\Core;

use Raven\Lib\Config\ConfigFileStore;

/**
 * Loads and saves Raven configuration from a PHP array file.
 *
 * The panel uses this class to safely edit config values while keeping the
 * canonical config format in `private/dat/config.php`.
 */
final class Config
{
    /** @var array<string, mixed> Parsed config tree. */
    private array $data;

    /** Absolute path to the config file on disk. */
    private string $path;
    private ConfigFileStore $store;

    /**
     * @param string $path Absolute path to `private/dat/config.php`.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->store = new ConfigFileStore();
        $this->data = $this->store->load($path);
    }

    /**
     * Returns full config tree for read-only use.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Reads a config value using dot notation, e.g. `panel.path`.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $cursor = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Writes a config value into the in-memory tree via dot notation.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->data;

        foreach ($segments as $index => $segment) {
            // Create intermediate arrays when a new path segment is introduced.
            if (!is_array($cursor)) {
                $cursor = [];
            }

            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * Replaces full config tree in memory.
     *
     * @param array<string, mixed> $data
     */
    public function replace(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Persists the current config tree back to disk in executable PHP format.
     */
    public function save(): void
    {
        $this->store->save($this->path, $this->data);
    }
}
