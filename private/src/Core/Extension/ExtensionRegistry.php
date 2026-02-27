<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Extension/ExtensionRegistry.php
 * Shared extension state and manifest parsing helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Extension;

/**
 * Centralizes extension registry parsing so bootstrap/panel/public stay in sync.
 */
final class ExtensionRegistry
{
    /**
     * Returns enabled extension directory map from `private/ext/.state.php`.
     *
     * @return array<string, bool>
     */
    public static function enabledMap(string $root): array
    {
        $state = self::loadState($root);
        /** @var mixed $rawEnabled */
        $rawEnabled = $state['enabled'] ?? [];
        if (!is_array($rawEnabled)) {
            return [];
        }

        $enabled = [];
        foreach ($rawEnabled as $directory => $flag) {
            if (
                !is_string($directory)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directory) !== 1
            ) {
                continue;
            }

            if ((bool) $flag) {
                $enabled[$directory] = true;
            }
        }

        return $enabled;
    }

    /**
     * Returns extension permission-bit map from `private/ext/.state.php`.
     *
     * @param array<int, int> $allowedBits
     * @return array<string, int>
     */
    public static function permissionMap(string $root, array $allowedBits = []): array
    {
        $state = self::loadState($root);
        /** @var mixed $rawPermissions */
        $rawPermissions = $state['permissions'] ?? [];
        if (!is_array($rawPermissions)) {
            return [];
        }

        $permissions = [];
        foreach ($rawPermissions as $directory => $rawBit) {
            if (
                !is_string($directory)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directory) !== 1
            ) {
                continue;
            }

            $bit = (int) $rawBit;
            if ($allowedBits !== [] && !in_array($bit, $allowedBits, true)) {
                continue;
            }

            $permissions[$directory] = $bit;
        }

        return $permissions;
    }

    /**
     * Returns enabled extension directories that exist on disk.
     *
     * @return array<int, string>
     */
    public static function enabledDirectories(string $root, bool $requireValidManifest = true): array
    {
        $directories = [];
        foreach (array_keys(self::enabledMap($root)) as $directory) {
            $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directory;
            if (!is_dir($extensionRoot)) {
                continue;
            }

            if ($requireValidManifest && self::readManifest($root, $directory) === null) {
                continue;
            }

            $directories[] = $directory;
        }

        return $directories;
    }

    /**
     * Reads one extension manifest and returns normalized metadata.
     *
     * @return array{
     *   name: string,
     *   type: string,
     *   panel_path: string,
     *   panel_section: string,
     *   system_extension: bool
     * }|null
     */
    public static function readManifest(string $root, string $directoryName): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directoryName) !== 1) {
            return null;
        }

        $manifestPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/extension.json';
        if (!is_file($manifestPath)) {
            return null;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $name = trim((string) ($decoded['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $type = strtolower(trim((string) ($decoded['type'] ?? 'basic')));
        if (!in_array($type, ['basic', 'system', 'helper'], true)) {
            $type = 'basic';
        }

        // Extension routing identity is standardized on directory slug.
        // Keep helper extensions non-routable/invisible by clearing both keys.
        $panelPath = $type === 'helper' ? '' : $directoryName;
        $panelSection = $type === 'helper' ? '' : $directoryName;

        return [
            'name' => $name,
            'type' => $type,
            'panel_path' => $panelPath,
            'panel_section' => $panelSection,
            'system_extension' => (bool) ($decoded['system_extension'] ?? false),
        ];
    }

    /**
     * Loads extension state from disk and normalizes legacy layout.
     *
     * @return array{enabled: array<string, mixed>, permissions: array<string, mixed>}
     */
    private static function loadState(string $root): array
    {
        $statePath = rtrim($root, '/') . '/private/ext/.state.php';
        if (!is_file($statePath)) {
            return ['enabled' => [], 'permissions' => []];
        }

        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        /** @var mixed $rawState */
        $rawState = require $statePath;
        if (!is_array($rawState)) {
            return ['enabled' => [], 'permissions' => []];
        }

        /** @var mixed $enabled */
        $enabled = array_key_exists('enabled', $rawState) ? $rawState['enabled'] : $rawState;
        if (!array_key_exists('enabled', $rawState) && array_key_exists('permissions', $rawState)) {
            $enabled = [];
        }

        /** @var mixed $permissions */
        $permissions = $rawState['permissions'] ?? [];

        return [
            'enabled' => is_array($enabled) ? $enabled : [],
            'permissions' => is_array($permissions) ? $permissions : [],
        ];
    }
}
