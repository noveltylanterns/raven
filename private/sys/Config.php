<?php

/**
 * RAVEN CMS
 * ~/private/sys/Config.php
 * Core runtime configuration loader, reader, writer, and persister.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core;

use Raven\Lib\Config\ConfigValueWriter;
use RuntimeException;

/**
 * Lean runtime configuration manager for Raven core internals.
 *
 * Owns the request-lifecycle config contract: loading a PHP array file into
 * memory, reading and writing values via dot-notation, and replacing the
 * entire in-memory tree. On-disk persistence format is delegated to
 * `Raven\Lib\Config\ConfigValueWriter::persist()`.
 *
 * Static type-coercion helpers (`bool`, `int`, `float`) belong in
 * `Raven\Lib\Config\ConfigValueParser` and are intentionally absent from this
 * class. Extension code needing value parsing should import `ConfigValueParser`
 * directly alongside `Raven\Core\Config` for instance access.
 */
class Config
{
    /** @var array<string, mixed> Parsed config tree held in memory for this request. */
    private array $data;

    /** Absolute path to the config PHP file on disk. */
    private string $path;

    /**
     * Loads the config file at `$path` into memory.
     *
     * The file must return a PHP array; anything else is a hard failure since
     * the rest of the application cannot safely proceed without a valid config.
     *
     * @param string $path Absolute path to `private/dat/config.php`.
     * @throws RuntimeException When the file does not return an array.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Config file must return an array.');
        }

        $this->data = $loaded;
    }

    /**
     * Returns the full config tree for read-only inspection.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Reads one config value using dot notation (e.g. `panel.path`).
     *
     * Traverses nested arrays segment by segment; returns `$default` when any
     * segment is missing or the current cursor is not an array.
     *
     * @param string $key     Dot-delimited config key path.
     * @param mixed  $default Value to return when the key does not exist.
     * @return mixed The resolved config value, or `$default`.
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
     * Writes one config value into the in-memory tree using dot notation.
     *
     * Intermediate arrays are created as needed when a new key path is introduced.
     * Changes are not persisted until `save()` is called.
     *
     * @param string $key   Dot-delimited config key path.
     * @param mixed  $value Value to store at the resolved path.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->data;

        foreach ($segments as $index => $segment) {
            // Create intermediate arrays when a new key path is introduced.
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
     * Replaces the entire in-memory config tree.
     *
     * Used by the panel config editor after normalizing and validating a full
     * config snapshot. Changes are not persisted until `save()` is called.
     *
     * @param array<string, mixed> $data Complete replacement config tree.
     * @return void
     */
    public function replace(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Persists the current in-memory config tree back to disk.
     *
     * Delegates all on-disk format concerns to `Raven\Lib\Config\ConfigValueWriter::persist()`.
     *
     * @throws RuntimeException When the file cannot be written.
     * @return void
     */
    public function save(): void
    {
        ConfigValueWriter::persist($this->path, $this->data);
    }
}
