<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Security/InputSanitizer.php
 * Security utility for validation and protections.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Security;

/**
 * Centralized input sanitization and validation utilities.
 *
 * Public and panel controllers should exclusively use this class for
 * request-derived scalar values to keep behavior consistent.
 */
final class InputSanitizer
{
    /**
     * Normalizes generic text input by trimming, stripping control chars,
     * and enforcing length limits.
     */
    public function text(?string $value, int $maxLength = 255): string
    {
        $value ??= '';
        $value = trim($value);

        // Remove non-printable control characters to avoid log/view issues.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Keeps rich HTML content for editors while removing NULL bytes.
     */
    public function html(?string $value, int $maxLength = 200000): string
    {
        $value ??= '';

        // Keep HTML intact for TinyMCE content; only remove dangerous nulls.
        $value = str_replace("\0", '', $value);

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * Validates and normalizes a slug value.
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
     * Validates email format and returns normalized lowercase value.
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
     * Validates and normalizes a username value.
     *
     * Rules:
     * - 3..50 chars
     * - lowercase a-z, 0-9, underscore, hyphen, dot
     * - must start with alphanumeric
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
     * Converts input to bounded integer; returns null if invalid.
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
