<?php

declare(strict_types=1);

namespace Raven\Lib\Diagnostics\Toolbar;

/**
 * Sanitizes request/environment payloads for debug toolbar rendering.
 */
final class DebugToolbarDataSanitizer
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
                $item = trim($item);
                if (strlen($item) > 500) {
                    $item = substr($item, 0, 500) . '...';
                }
                $output[$key] = $item;
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
                $value = trim($value);
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
}
