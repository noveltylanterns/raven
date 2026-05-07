<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ConfigParser.php
 * Backward-compatibility wrapper for config read helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\ConfigRead;

/**
 * Compatibility shim that forwards legacy parser calls to `ConfigRead`.
 */
final class ConfigParser
{
    /** @var array<string, array<int, string>> */
    private static array $pathSegmentsCache = [];

    /**
     * @param string $key
     * @return array<int, string>
     */
    public static function segments(string $key): array
    {
        return ConfigRead::segments($key);
    }

    /**
     * @param array<string, mixed> $config
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(array $config, string $key, mixed $default = null): mixed
    {
        return ConfigRead::get($config, $key, $default);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $segments
     * @param mixed $default
     * @return mixed
     */
    public static function getBySegments(array $config, array $segments, mixed $default = null): mixed
    {
        return ConfigRead::getBySegments($config, $segments, $default);
    }

    /**
     * @param mixed $value
     * @param bool $default
     * @return bool
     */
    public static function bool(mixed $value, bool $default = false): bool
    {
        return ConfigRead::bool($value, $default);
    }

    /**
     * @param mixed $value
     * @param int $default
     * @param int|null $min
     * @param int|null $max
     * @return int
     */
    public static function int(mixed $value, int $default, ?int $min = null, ?int $max = null): int
    {
        return ConfigRead::int($value, $default, $min, $max);
    }

    /**
     * @param mixed $value
     * @param float $default
     * @param float|null $min
     * @param float|null $max
     * @return float
     */
    public static function float(mixed $value, float $default, ?float $min = null, ?float $max = null): float
    {
        return ConfigRead::float($value, $default, $min, $max);
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<int, string> $segments
     * @return string
     */
    public static function readNestedString(array $submitted, array $segments): string
    {
        return ConfigRead::readNestedString($submitted, $segments);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function detectScalarType(mixed $value): string
    {
        return ConfigRead::detectScalarType($value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function stringifyScalar(mixed $value): string
    {
        return ConfigRead::stringifyScalar($value);
    }
}
