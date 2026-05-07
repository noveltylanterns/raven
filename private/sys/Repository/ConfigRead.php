<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/ConfigRead.php
 * Lightweight config-reading and scalar type-coercion helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

/**
 * Standalone scalar type-coercion helpers for Raven config values.
 *
 * Accepts the common string/numeric/boolean forms that Raven stores in config
 * files and HTML forms, normalizing them to typed PHP primitives.
 */
final class ConfigRead
{
    /** @var array<string, array<int, string>> */
    private static array $pathSegmentsCache = [];

    /**
     * Returns cached dot-path segments for one config key.
     *
     * @param string $key Dot-delimited config key path.
     * @return array<int, string> Cached path segments.
     */
    public static function segments(string $key): array
    {
        $normalized = trim($key);
        if ($normalized === '') {
            return [];
        }

        if (isset(self::$pathSegmentsCache[$normalized])) {
            return self::$pathSegmentsCache[$normalized];
        }

        self::$pathSegmentsCache[$normalized] = str_contains($normalized, '.')
            ? explode('.', $normalized)
            : [$normalized];

        return self::$pathSegmentsCache[$normalized];
    }

    /**
     * Reads one config value from a nested array using dot notation.
     *
     * @param array<string, mixed> $config Config tree to read from.
     * @param string $key Dot-delimited config key path.
     * @param mixed $default Value returned when the path does not resolve.
     * @return mixed Resolved config value or the provided default.
     */
    public static function get(array $config, string $key, mixed $default = null): mixed
    {
        return self::getBySegments($config, self::segments($key), $default);
    }

    /**
     * Reads one config value from a nested array using pre-split path segments.
     *
     * @param array<string, mixed> $config Config tree to read from.
     * @param array<int, string> $segments Pre-split config path segments.
     * @param mixed $default Value returned when the path does not resolve.
     * @return mixed Resolved config value or the provided default.
     */
    public static function getBySegments(array $config, array $segments, mixed $default = null): mixed
    {
        $cursor = $config;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Normalizes one mixed config value into a boolean.
     *
     * @param mixed $value Raw config value.
     * @param bool  $default Fallback when the input cannot be normalized.
     * @return bool Normalized boolean config value.
     */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Normalizes one mixed config value into an integer.
     *
     * @param mixed    $value   Raw config value.
     * @param int      $default Fallback when the input cannot be normalized.
     * @param int|null $min     Optional lower bound applied after parsing.
     * @param int|null $max     Optional upper bound applied after parsing.
     * @return int Normalized integer config value.
     */
    public static function int(mixed $value, int $default, ?int $min = null, ?int $max = null): int
    {
        $parsed = $default;
        if (is_int($value) || is_float($value)) {
            $parsed = (int) $value;
        } elseif (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            $parsed = (int) trim($value);
        }

        if ($min !== null && $parsed < $min) {
            $parsed = $min;
        }
        if ($max !== null && $parsed > $max) {
            $parsed = $max;
        }

        return $parsed;
    }

    /**
     * Normalizes one mixed config value into a float.
     *
     * @param mixed      $value   Raw config value.
     * @param float      $default Fallback when the input cannot be normalized.
     * @param float|null $min     Optional lower bound applied after parsing.
     * @param float|null $max     Optional upper bound applied after parsing.
     * @return float Normalized float config value.
     */
    public static function float(mixed $value, float $default, ?float $min = null, ?float $max = null): float
    {
        $parsed = $default;
        if (is_int($value) || is_float($value)) {
            $parsed = (float) $value;
        } elseif (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            $parsed = (float) trim($value);
        }

        if ($min !== null && $parsed < $min) {
            $parsed = $min;
        }
        if ($max !== null && $parsed > $max) {
            $parsed = $max;
        }

        return $parsed;
    }

    /**
     * Reads one submitted nested scalar value and normalizes it to a string.
     *
     * @param array<string, mixed> $submitted Nested submitted data.
     * @param array<int, string> $segments Nested config path segments.
     * @return string Submitted scalar value, or an empty string when absent.
     */
    public static function readNestedString(array $submitted, array $segments): string
    {
        $cursor = $submitted;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return '';
            }

            $cursor = $cursor[$segment];
        }

        if (is_string($cursor)) {
            return $cursor;
        }

        if (is_int($cursor) || is_float($cursor) || is_bool($cursor)) {
            return (string) $cursor;
        }

        return '';
    }

    /**
     * Detects the scalar type label used by the panel config editor.
     *
     * @param mixed $value Raw config value.
     * @return string Scalar type label for form normalization.
     */
    public static function detectScalarType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            default => 'string',
        };
    }

    /**
     * Stringifies one scalar config value for panel form fields.
     *
     * @param mixed $value Raw config value.
     * @return string Normalized string form used by the panel editor.
     */
    public static function stringifyScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
