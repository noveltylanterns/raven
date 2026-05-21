<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/StateRead.php
 * Read-side loader for extension enablement state.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Shared read-side service for extension enablement and permission state maps.
 */
final class StateRead
{
    private string $extensionsBasePath;
    private string $stateBasePath;
    private StateWrite $stateWrite;

    /**
     * Prepares the extension-state reader for one project tree.
     *
     * @param string $extensionsBasePath Absolute `private/ext` directory path.
     * @param string|null $stateBasePath Optional absolute `private/dat/ext` directory path override.
     * @return void
     */
    public function __construct(string $extensionsBasePath, ?string $stateBasePath = null)
    {
        $this->extensionsBasePath = rtrim($extensionsBasePath, '/\\');
        $this->stateBasePath = $stateBasePath !== null
            ? rtrim($stateBasePath, '/\\')
            : dirname($this->extensionsBasePath) . '/dat/ext';
        $this->stateWrite = new StateWrite($this->extensionsBasePath, $this->stateBasePath);
    }

    /**
     * Returns the canonical extension-directory base path.
     *
     * @return string Absolute `private/ext` path.
     */
    public function basePath(): string
    {
        return $this->extensionsBasePath;
    }

    /**
     * Returns the canonical extension-state file path.
     *
     * @return string Absolute `private/dat/ext/.state.php` path.
     */
    public function stateFilePath(): string
    {
        return $this->stateBasePath . '/.state.php';
    }

    /**
     * Ensures the extension install base path exists.
     *
     * @return void
     */
    public function ensureDirectory(): void
    {
        // No-op when the extension install directory already exists.
        if (is_dir($this->extensionsBasePath)) {
            return;
        }

        // Create directory recursively and verify success.
        if (!mkdir($this->extensionsBasePath, 0775, true) && !is_dir($this->extensionsBasePath)) {
            throw new \RuntimeException('Failed to create private/ext directory.');
        }
    }

    /**
     * Ensures the extension-state directory exists.
     *
     * Exposes state-directory provisioning for flows that both read and write
     * extension state through the same service boundary.
     *
     * @return void
     */
    public function ensureStateDirectory(): void
    {
        $this->stateWrite->ensureStateDirectory();
    }

    /**
     * Loads and normalizes the full extension state payload from disk.
     *
     * Clears OPcache before reading so the file reflects the most recent write even
     * under long-lived PHP-FPM processes.
     *
     * @return array{
     *   enabled: array<string, bool>,
     *   permissions: array<string, int>,
     *   permission_bits: array<string, array<string, int>>
     * } Normalized state payload with empty maps when the state file is absent or unreadable.
     */
    public function loadStateData(): array
    {
        $statePath = $this->stateFilePath();
        // Missing state file yields default empty state maps.
        if (!is_file($statePath)) {
            return $this->defaultStateData();
        }

        clearstatcache(true, $statePath);
        // Invalidate OPcache copy so reads see the latest state write.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        /** @var mixed $loaded */
        $loaded = require $statePath;
        // Non-array state payloads are treated as invalid and reset to defaults.
        if (!is_array($loaded)) {
            return $this->defaultStateData();
        }

        /** @var mixed $rawEnabled */
        $rawEnabled = $loaded['enabled'] ?? [];
        /** @var mixed $rawPermissions */
        $rawPermissions = $loaded['permissions'] ?? [];
        /** @var mixed $rawPermissionBits */
        $rawPermissionBits = $loaded['permission_bits'] ?? [];

        return [
            'enabled' => $this->normalizeEnabledMap(is_array($rawEnabled) ? $rawEnabled : []),
            'permissions' => $this->normalizePermissionMap(is_array($rawPermissions) ? $rawPermissions : []),
            'permission_bits' => $this->normalizePermissionBitsMap(is_array($rawPermissionBits) ? $rawPermissionBits : []),
        ];
    }

    /**
     * Returns only the enabled-extension map from the persisted state.
     *
     * @return array<string, bool> Enabled map keyed by extension directory slug.
     */
    public function loadEnabledMap(): array
    {
        return $this->loadStateData()['enabled'];
    }

    /**
     * Returns only the permission-bit map from the persisted state.
     *
     * @return array<string, int> Permission map keyed by extension directory slug.
     */
    public function loadPermissionMap(): array
    {
        return $this->loadStateData()['permissions'];
    }

    /**
     * Returns only the level-to-bit map from the persisted state.
     *
     * @return array<string, array<string, int>> Level-to-bit map keyed by extension directory slug.
     */
    public function loadPermissionBitsMap(): array
    {
        return $this->loadStateData()['permission_bits'];
    }

    /**
     * Persists an updated enabled-extension map, preserving existing permission state.
     *
     * @param array<string, bool> $enabledMap New enabled map to persist.
     * @return void
     */
    public function saveEnabledMap(array $enabledMap): void
    {
        $state = $this->loadStateData();
        $this->saveState($enabledMap, $state['permissions'], $state['permission_bits']);
    }

    /**
     * Persists an updated permission-bit map, preserving existing enabled and bits state.
     *
     * @param array<string, int> $permissionMap New permission map to persist.
     * @return void
     */
    public function savePermissionMap(array $permissionMap): void
    {
        $state = $this->loadStateData();
        $this->saveState($state['enabled'], $permissionMap, $state['permission_bits']);
    }

    /**
     * Persists an updated level-to-bit map, preserving existing enabled and permission state.
     *
     * @param array<string, array<string, int>> $permissionBitsMap New bits map to persist.
     * @return void
     */
    public function savePermissionBitsMap(array $permissionBitsMap): void
    {
        $state = $this->loadStateData();
        $this->saveState($state['enabled'], $state['permissions'], $permissionBitsMap);
    }

    /**
     * Persists the full extension state map in one atomic write.
     *
     * When `$permissionBitsMap` is omitted the currently persisted bits map is preserved.
     *
     * @param array<string, bool> $enabledMap Enabled-extension map to persist.
     * @param array<string, int> $permissionMap Permission-bit map to persist.
     * @param array<string, array<string, int>> $permissionBitsMap Level-to-bit map to persist; omit to keep existing.
     * @return void
     */
    public function saveState(array $enabledMap, array $permissionMap, array $permissionBitsMap = []): void
    {
        $currentState = $this->loadStateData();
        // Preserve currently stored permission bits when caller omits them.
        if ($permissionBitsMap === []) {
            $permissionBitsMap = $currentState['permission_bits'];
        }
        $this->stateWrite->saveState(
            $enabledMap,
            $permissionMap,
            $permissionBitsMap,
            $currentState['enabled']
        );
    }

    /**
     * Validates one extension directory name before filesystem reads.
     *
     * @param string $name Extension directory name candidate.
     * @return bool True when the name matches Raven's safe slug pattern.
     */
    private function isSafeDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }

    /**
     * @return array{
     *   enabled: array<string, bool>,
     *   permissions: array<string, int>,
     *   permission_bits: array<string, array<string, int>>
     * }
     */
    private function defaultStateData(): array
    {
        return [
            'enabled' => [],
            'permissions' => [],
            'permission_bits' => [],
        ];
    }

    /**
     * @param array<string, mixed> $enabledMap
     * @return array<string, bool>
     */
    private function normalizeEnabledMap(array $enabledMap): array
    {
        $normalized = [];
        // Keep only safe extension slugs with truthy enabled flags.
        foreach ($enabledMap as $name => $isEnabled) {
            $directory = (string) $name;
            // Ignore unsafe slugs and disabled entries.
            if ($this->isSafeDirectoryName($directory) && (bool) $isEnabled) {
                $normalized[$directory] = true;
            }
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param array<string, mixed> $permissionMap
     * @return array<string, int>
     */
    private function normalizePermissionMap(array $permissionMap): array
    {
        $normalized = [];
        // Keep only safe extension slugs with positive permission bits.
        foreach ($permissionMap as $name => $rawBit) {
            $directory = (string) $name;
            $bit = (int) $rawBit;
            // Ignore unsafe slugs and non-positive bits.
            if ($this->isSafeDirectoryName($directory) && $bit > 0) {
                $normalized[$directory] = $bit;
            }
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param array<string, mixed> $permissionBitsMap
     * @return array<string, array<string, int>>
     */
    private function normalizePermissionBitsMap(array $permissionBitsMap): array
    {
        $normalized = [];
        // Normalize per-extension level-to-bit maps.
        foreach ($permissionBitsMap as $name => $levelsRaw) {
            $directory = (string) $name;
            // Skip unsafe extension slugs or malformed level maps.
            if (!$this->isSafeDirectoryName($directory) || !is_array($levelsRaw)) {
                continue;
            }

            $normalizedLevels = [];
            // Keep only safe level keys mapped to positive bit values.
            foreach ($levelsRaw as $levelKey => $rawBit) {
                $level = strtolower(trim((string) $levelKey));
                $bit = (int) $rawBit;
                // Ignore malformed level keys or non-positive bits.
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $level) === 1 && $bit > 0) {
                    $normalizedLevels[$level] = $bit;
                }
            }

            // Keep extension entry only when at least one normalized level survived.
            if ($normalizedLevels !== []) {
                ksort($normalizedLevels);
                $normalized[$directory] = $normalizedLevels;
            }
        }

        ksort($normalized);
        return $normalized;
    }
}
