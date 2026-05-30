<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/ConfigRead.php
 * Repository-style config-reading and scalar type-coercion helpers.
 * Docs: https://lanterns.io/raven
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
        // Empty keys cannot resolve to a usable config path.
        if ($normalized === '') {
            return [];
        }

        // Reuse cached segment arrays for repeated key lookups.
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

        // Walk each path segment and bail out to default as soon as traversal fails.
        foreach ($segments as $segment) {
            // Missing segment or non-array cursor means the key path does not resolve.
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
        // Preserve booleans exactly as provided.
        if (is_bool($value)) {
            return $value;
        }

        // Numeric values normalize to true for non-zero and false for zero.
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        // String values support common boolean word forms from config/forms.
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            // Accept explicit truthy words.
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            // Accept explicit falsy words and empty string.
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
        // Numeric scalar values are cast directly to integer.
        if (is_int($value) || is_float($value)) {
            $parsed = (int) $value;
        } elseif (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            $parsed = (int) trim($value);
        }

        // Apply optional lower bound after parsing.
        if ($min !== null && $parsed < $min) {
            $parsed = $min;
        }
        // Apply optional upper bound after parsing.
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
        // Numeric scalar values are cast directly to float.
        if (is_int($value) || is_float($value)) {
            $parsed = (float) $value;
        } elseif (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            $parsed = (float) trim($value);
        }

        // Apply optional lower bound after parsing.
        if ($min !== null && $parsed < $min) {
            $parsed = $min;
        }
        // Apply optional upper bound after parsing.
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

        // Traverse each segment and return empty string as soon as path resolution fails.
        foreach ($segments as $segment) {
            // Missing segment or non-array cursor means no submitted scalar exists at this path.
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return '';
            }

            $cursor = $cursor[$segment];
        }

        // Preserve raw submitted strings exactly for caller-side validation.
        if (is_string($cursor)) {
            return $cursor;
        }

        // Coerce scalar primitives to strings for consistent form handling.
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
        // Encode booleans explicitly as human-readable literals for form round-trips.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // Null maps to empty field value in panel form inputs.
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
