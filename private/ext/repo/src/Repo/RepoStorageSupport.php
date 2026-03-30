<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/src/Repo/RepoStorageSupport.php
 * Shared file persistence helpers for the Repo extension.
 * Docs: /private/ext/repo/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Repo;

use RuntimeException;

/**
 * Shared helpers for extension-local JSON persistence.
 */
final class RepoStorageSupport
{
    /**
     * Keep rewritten JSON-object payloads coherent for the rest of the current request.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $jsonObjectCache = [];

    /**
     * Keep rewritten JSON-array payloads coherent for the rest of the current request.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $jsonArrayCache = [];

    /**
     * Ensures the parent directory exists for one target file path.
     *
     * @param string $path Absolute file path that will be written.
     * @return void Parent directory is created when missing.
     */
    public static function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create directory for repo extension storage file.');
        }
    }

    /**
     * Loads one JSON-object file and falls back safely when missing or invalid.
     *
     * @param string $path Absolute file path.
     * @return array<string, mixed> Decoded object payload, or an empty array when unavailable.
     */
    public static function loadJsonObjectFile(string $path): array
    {
        if (!is_file($path)) {
            unset(self::$jsonObjectCache[$path]);
            return [];
        }

        if (isset(self::$jsonObjectCache[$path])) {
            return self::$jsonObjectCache[$path];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            self::$jsonObjectCache[$path] = [];
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        $normalized = is_array($decoded) ? $decoded : [];
        self::$jsonObjectCache[$path] = $normalized;
        return $normalized;
    }

    /**
     * Writes one JSON object file using pretty-printing for human inspection.
     *
     * @param string $path Absolute file path.
     * @param array<string, mixed> $payload Object payload to persist.
     * @return void File is written atomically with lock semantics.
     */
    public static function writeJsonObjectFile(string $path, array $payload): void
    {
        self::ensureParentDirectory($path);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Failed to encode repo extension JSON object file.');
        }

        if (file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Failed to write repo extension JSON object file.');
        }

        clearstatcache(true, $path);
        self::$jsonObjectCache[$path] = $payload;
    }

    /**
     * Loads one JSON-array file and falls back safely when missing or invalid.
     *
     * @param string $path Absolute file path.
     * @return array<int, array<string, mixed>> Decoded log/list payload.
     */
    public static function loadJsonArrayFile(string $path): array
    {
        if (!is_file($path)) {
            unset(self::$jsonArrayCache[$path]);
            return [];
        }

        if (isset(self::$jsonArrayCache[$path])) {
            return self::$jsonArrayCache[$path];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            self::$jsonArrayCache[$path] = [];
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        $normalized = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        self::$jsonArrayCache[$path] = $normalized;
        return $normalized;
    }

    /**
     * Writes one JSON array file using pretty-printing for human inspection.
     *
     * @param string $path Absolute file path.
     * @param array<int, array<string, mixed>> $payload Array payload to persist.
     * @return void File is written atomically with lock semantics.
     */
    public static function writeJsonArrayFile(string $path, array $payload): void
    {
        self::ensureParentDirectory($path);

        $encoded = json_encode(array_values($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Failed to encode repo extension JSON storage file.');
        }

        if (file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Failed to write repo extension JSON storage file.');
        }

        clearstatcache(true, $path);
        self::$jsonArrayCache[$path] = array_values($payload);
    }
}