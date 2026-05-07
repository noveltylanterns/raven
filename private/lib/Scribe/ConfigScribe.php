<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/ConfigScribe.php
 * Backward-compatibility wrapper for config write helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Core\Repository\ConfigWrite;

/**
 * Compatibility shim that forwards legacy scribe calls to `ConfigWrite`.
 */
final class ConfigScribe
{
    /**
     * @param array<string, mixed> $config
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set(array &$config, string $key, mixed $value): void
    {
        ConfigWrite::set($config, $key, $value);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     * @param mixed $value
     * @return void
     */
    public static function setNested(array &$config, array $segments, mixed $value): void
    {
        ConfigWrite::setNested($config, $segments, $value);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $data
     * @param string $key
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function persistValue(string $path, array $data, string $key, mixed $value): array
    {
        return ConfigWrite::persistValue($path, $data, $key, $value);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $data
     * @return void
     */
    public static function persist(string $path, array $data): void
    {
        ConfigWrite::persist($path, $data);
    }
}
