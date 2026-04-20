<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/ProfilerDataSanitizer.php
 * Output profiler request and environment payload sanitizer.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Sanitizes request/environment payloads for output profiler rendering.
 */
final class ProfilerDataSanitizer
{
    /**
     * @param array<int|string, mixed> $value
     */
    public static function prettyJson(array $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return $encoded;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    public static function sanitizeArray(array $value, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['__truncated' => true];
        }

        $output = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            if (preg_match('/password|passwd|secret|token|authorization|cookie|csrf/', $keyText) === 1) {
                $output[$key] = '[redacted]';
                continue;
            }

            if (is_array($item)) {
                $output[$key] = self::sanitizeArray($item, $depth + 1);
                continue;
            }

            if (is_string($item)) {
                $output[$key] = self::sanitizeStringValue($item);
                continue;
            }

            if (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $output[$key] = $item;
                continue;
            }

            if (is_object($item)) {
                $output[$key] = '[object ' . $item::class . ']';
                continue;
            }

            $output[$key] = '[' . gettype($item) . ']';
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function sanitizeServer(array $server): array
    {
        $allowed = [
            'REQUEST_METHOD',
            'REQUEST_URI',
            'SCRIPT_NAME',
            'QUERY_STRING',
            'HTTP_HOST',
            'SERVER_NAME',
            'SERVER_ADDR',
            'SERVER_PORT',
            'REMOTE_ADDR',
            'HTTP_USER_AGENT',
            'HTTPS',
            'REQUEST_TIME_FLOAT',
        ];

        $filtered = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $server)) {
                continue;
            }

            $value = $server[$key];
            if (is_string($value)) {
                $value = self::sanitizeStringValue($value);
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                continue;
            }

            $normalized[$key] = [
                'name' => $file['name'] ?? '',
                'type' => $file['type'] ?? '',
                'size' => $file['size'] ?? 0,
                'error' => $file['error'] ?? null,
            ];
        }

        return $normalized;
    }

    private static function sanitizeStringValue(string $value): string
    {
        $value = trim($value);
        if (strlen($value) > 500) {
            $value = substr($value, 0, 500) . '...';
        }

        $lower = strtolower($value);
        $looksHostile = str_contains($value, '<')
            || str_contains($value, '>')
            || str_contains($lower, 'javascript:')
            || str_contains($lower, 'data:text/html')
            || preg_match('/\bon[a-z0-9_-]+\s*=/i', $value) === 1
            || preg_match('/\bor\s+1\s*=\s*1\b/i', $value) === 1
            || preg_match('/\bunion\s+select\b/i', $value) === 1;

        if ($looksHostile) {
            return '[filtered potentially hostile input]';
        }

        return $value;
    }
}
