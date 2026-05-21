<?php

/**
 * RAVEN CMS
 * ~/private/sys/Config.php
 * Core runtime configuration loader and read-only accessor.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core;

use Raven\Core\Repository\ConfigRead;
use RuntimeException;

/**
 * Lean runtime configuration manager for Raven core internals.
 *
 * Owns the request-lifecycle config read contract: loading a PHP array file
 * into memory and exposing dot-notation reads for the active request.
 *
 * Nested writes and persistence belong in `Raven\Core\Repository\ConfigWrite`.
 * Scalar coercion and dot-path parsing live in `Raven\Core\Repository\ConfigRead`.
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
     * Returns the absolute config-file path currently backing this object.
     *
     * @return string Absolute path to the loaded config file.
     */
    public function path(): string
    {
        return $this->path;
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
        return ConfigRead::get($this->data, $key, $default);
    }
}
