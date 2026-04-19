<?php

/**
 * RAVEN CMS
 * ~/private/lib/Config/ConfigParser.php
 * Lightweight scalar type-coercion helpers for config values.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Config;

/**
 * Standalone scalar type-coercion helpers for Raven config values.
 *
 * Accepts the common string/numeric/boolean forms that Raven stores in config
 * files and HTML forms, normalizing them to typed PHP primitives. Kept separate
 * from the runtime `Config` class so extension code and lib services can import
 * just the parsing primitive without pulling in I/O concerns.
 *
 * Call this class directly when only value parsing is needed without the full
 * config I/O surface (`Raven\Core\Config`).
 */
final class ConfigParser
{
    /**
     * Normalizes one mixed config value into a boolean.
     *
     * Accepts the common string/numeric forms that Raven stores in config files
     * and HTML forms so callers can normalize without repeating local parsing.
     *
     * @param mixed $value   Raw config value.
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
}
