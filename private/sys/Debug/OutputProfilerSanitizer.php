<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/OutputProfilerSanitizer.php
 * Output profiler request and environment payload sanitizer.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Sanitizes request/environment payloads for output profiler rendering.
 */
final class OutputProfilerSanitizer
{
    /**
     * Encodes one associative payload as pretty-printed JSON for debug display.
     *
     * @param array<int|string, mixed> $value
     * @return string Pretty-printed JSON string, or `{}` when encoding fails.
     */
    public static function prettyJson(array $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Return deterministic fallback output when JSON encoding fails on mixed payload values.
        if (!is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return $encoded;
    }

    /**
     * Recursively sanitizes one nested array, redacting sensitive keys and hostile payloads.
     *
     * @param array<int|string, mixed> $value
     * @param int $depth Current recursion depth used to enforce max nesting.
     * @return array<int|string, mixed>
     */
    public static function sanitizeArray(array $value, int $depth = 0): array
    {
        // Bound recursion depth so hostile or cyclic-like payloads cannot explode debug output.
        if ($depth > 6) {
            return ['__truncated' => true];
        }

        $output = [];
        // Walk each key/value and normalize according to sensitivity and scalar type.
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            // Redact common secret-bearing fields before they reach profiler HTML.
            if (preg_match('/password|passwd|secret|token|authorization|cookie|csrf/', $keyText) === 1) {
                $output[$key] = '[redacted]';
                continue;
            }

            // Recurse nested arrays with depth tracking to keep redaction behavior consistent.
            if (is_array($item)) {
                $output[$key] = self::sanitizeArray($item, $depth + 1);
                continue;
            }

            // Apply hostile-payload filtering to string values shown in debug UI.
            if (is_string($item)) {
                $output[$key] = self::sanitizeStringValue($item);
                continue;
            }

            // Keep primitive scalars unchanged so numeric/boolean diagnostics stay readable.
            if (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $output[$key] = $item;
                continue;
            }

            // Preserve class identity without exposing full object internals.
            if (is_object($item)) {
                $output[$key] = '[object ' . $item::class . ']';
                continue;
            }

            $output[$key] = '[' . gettype($item) . ']';
        }

        return $output;
    }

    /**
     * Filters the server payload down to a safe allow-list for profiler rendering.
     *
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
        // Copy only allow-listed server keys to avoid leaking sensitive process/env values.
        foreach ($allowed as $key) {
            // Some SAPIs omit keys; skip absent entries rather than emitting null placeholders.
            if (!array_key_exists($key, $server)) {
                continue;
            }

            $value = $server[$key];
            // Server values are mostly strings and must pass the same hostile-input sanitizer.
            if (is_string($value)) {
                $value = self::sanitizeStringValue($value);
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * Normalizes uploaded-file metadata into one compact debug-safe structure.
     *
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public static function normalizeFiles(array $files): array
    {
        $normalized = [];
        // Keep uploaded-file diagnostics compact and consistent regardless of custom upload arrays.
        foreach ($files as $key => $file) {
            // Ignore malformed entries so profiler output remains predictable.
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

    /**
     * Sanitizes one scalar string, truncating oversized values and filtering hostile patterns.
     *
     * @param string $value Raw scalar string.
     * @return string Sanitized value safe for debug rendering.
     */
    private static function sanitizeStringValue(string $value): string
    {
        $value = trim($value);
        // Trim oversized scalar fields to keep debug payload rendering bounded.
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

        // Replace suspicious payloads with one safe marker before renderer-side HTML escaping.
        if ($looksHostile) {
            return '[filtered potentially hostile input]';
        }

        return $value;
    }
}
