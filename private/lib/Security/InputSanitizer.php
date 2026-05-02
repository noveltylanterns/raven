<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/InputSanitizer.php
 * Shared input sanitization and validation helpers for controller and lib use.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Standalone input sanitization and validation utilities.
 */
final class InputSanitizer
{
    /**
     * Trims, strips control characters, and truncates a plain-text string.
     *
     * @param string|null $value Raw input string; treated as empty when null.
     * @param int $maxLength Maximum allowed character length after trimming.
     * @return string Sanitized string, never null.
     */
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

    /**
     * Strips null bytes and truncates HTML/rich-text content.
     *
     * Does not strip tags so that body HTML from the editor is preserved intact.
     *
     * @param string|null $value Raw HTML input; treated as empty when null.
     * @param int $maxLength Maximum allowed character length.
     * @return string Sanitized string, never null.
     */
    public function html(?string $value, int $maxLength = 200000): string
    {
        $value ??= '';
        $value = str_replace("\0", '', $value);

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Normalizes a slug and returns null when the value does not match the slug character rules.
     *
     * Valid slugs are lowercase alphanumeric segments separated by single hyphens, max 160 chars.
     *
     * @param string|null $value Raw input.
     * @return string|null Valid slug string, or null when the value fails validation.
     */
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

    /**
     * Normalizes and validates an email address.
     *
     * @param string|null $value Raw input.
     * @return string|null Lowercase valid email address, or null when invalid.
     */
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

    /**
     * Normalizes and validates a username string.
     *
     * Valid usernames are 3–50 lowercase alphanumeric characters with optional dots, underscores,
     * or hyphens in interior positions.
     *
     * @param string|null $value Raw input.
     * @return string|null Lowercase valid username, or null when invalid.
     */
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

    /**
     * Parses and range-validates an integer from any scalar input type.
     *
     * @param mixed $value Raw value to parse; empty strings are treated as absent.
     * @param int $min Minimum accepted value (inclusive).
     * @param int $max Maximum accepted value (inclusive).
     * @return int|null Validated integer, or null when the value is missing, non-numeric, or out of range.
     */
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
