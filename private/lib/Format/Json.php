<?php

/**
 * RAVEN CMS
 * ~/private/lib/Format/Json.php
 * Canonical JSON encode/decode and file read/write helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Format;

/**
 * Shared JSON helpers for string and file payloads.
 */
final class Json
{
    /**
     * Decodes one JSON string to an associative array.
     *
     * @param string $json Raw JSON string.
     * @param int $maxDepth Maximum decode depth.
     * @return array<int|string, mixed>|null Decoded associative payload, or null on invalid/missing data.
     */
    public static function decode(string $json, int $maxDepth = 64): ?array
    {
        if (trim($json) === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, max(1, $maxDepth), JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Encodes one value to JSON.
     *
     * @param mixed $value Value to encode.
     * @param int $flags Json-encode flags (without JSON_THROW_ON_ERROR).
     * @return string|null Encoded JSON, or null on encode failure.
     */
    public static function encode(mixed $value, int $flags = JSON_UNESCAPED_SLASHES): ?string
    {
        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reads and decodes one JSON file to an associative array.
     *
     * @param string $path Absolute or relative JSON file path.
     * @param int $maxBytes Maximum bytes to read.
     * @param int $maxDepth Maximum decode depth.
     * @return array<int|string, mixed>|null Decoded payload, or null on read/decode failure.
     */
    public static function decodeFile(string $path, int $maxBytes = 10485760, int $maxDepth = 64): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path, false, null, 0, max(1, $maxBytes));
        if (!is_string($raw)) {
            return null;
        }

        return self::decode($raw, $maxDepth);
    }

    /**
     * Atomically writes one JSON payload to disk.
     *
     * @param string $path Target file path.
     * @param mixed $value Value to JSON-encode and write.
     * @param int $flags Json-encode flags (without JSON_THROW_ON_ERROR).
     * @return bool True when the write succeeds.
     */
    public static function writeFile(string $path, mixed $value, int $flags = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT): bool
    {
        if ($path === '') {
            return false;
        }

        $encoded = self::encode($value, $flags);
        if (!is_string($encoded)) {
            return false;
        }

        $tmpPath = $path . '.tmp.' . str_replace('.', '', uniqid('', true));
        if (@file_put_contents($tmpPath, $encoded, LOCK_EX) === false) {
            @unlink($tmpPath);
            return false;
        }

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }
}
