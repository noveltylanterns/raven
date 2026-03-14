<?php

declare(strict_types=1);

namespace Raven\Lib\Config;

/**
 * Shared scalar config-value parsing helpers.
 */
final class ConfigValueParser
{
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
