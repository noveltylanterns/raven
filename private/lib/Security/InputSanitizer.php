<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Standalone input sanitization and validation utilities.
 */
final class InputSanitizer
{
    public function text(?string $value, int $maxLength = 255): string
    {
        $value ??= '';
        $value = trim($value);

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    public function html(?string $value, int $maxLength = 200000): string
    {
        $value ??= '';
        $value = str_replace("\0", '', $value);

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    public function slug(?string $value): ?string
    {
        $value = strtolower($this->text($value, 160));

        if ($value === '') {
            return null;
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            return null;
        }

        return $value;
    }

    public function email(?string $value): ?string
    {
        $value = strtolower($this->text($value, 254));

        if ($value === '') {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $value;
    }

    public function username(?string $value): ?string
    {
        $value = strtolower($this->text($value, 50));

        if ($value === '') {
            return null;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,49}$/', $value)) {
            return null;
        }

        return $value;
    }

    public function int(mixed $value, int $min = 1, int $max = PHP_INT_MAX): ?int
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        $intValue = filter_var($value, FILTER_VALIDATE_INT);
        if ($intValue === false) {
            return null;
        }

        if ($intValue < $min || $intValue > $max) {
            return null;
        }

        return $intValue;
    }
}

