<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/StateRead.php
 * Read-side loader for extension enablement state.
 * Docs: https://raven.lanterns.io
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
        if (is_dir($this->extensionsBasePath)) {
            return;
        }

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
     * @return array{
     *   enabled: array<string, bool>,
     *   permissions: array<string, int>,
     *   permission_bits: array<string, array<string, int>>
     * }
     */
    public function loadStateData(): array
    {
        $statePath = $this->stateFilePath();
        if (!is_file($statePath)) {
            return $this->defaultStateData();
        }

        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        /** @var mixed $loaded */
        $loaded = require $statePath;
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
     * @return array<string, bool>
     */
    public function loadEnabledMap(): array
    {
        return $this->loadStateData()['enabled'];
    }

    /**
     * @return array<string, int>
     */
    public function loadPermissionMap(): array
    {
        return $this->loadStateData()['permissions'];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function loadPermissionBitsMap(): array
    {
        return $this->loadStateData()['permission_bits'];
    }

    /**
     * @param array<string, bool> $enabledMap
     */
    public function saveEnabledMap(array $enabledMap): void
    {
        $state = $this->loadStateData();
        $this->saveState($enabledMap, $state['permissions'], $state['permission_bits']);
    }

    /**
     * @param array<string, int> $permissionMap
     */
    public function savePermissionMap(array $permissionMap): void
    {
        $state = $this->loadStateData();
        $this->saveState($state['enabled'], $permissionMap, $state['permission_bits']);
    }

    /**
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    public function savePermissionBitsMap(array $permissionBitsMap): void
    {
        $state = $this->loadStateData();
        $this->saveState($state['enabled'], $state['permissions'], $permissionBitsMap);
    }

    /**
     * @param array<string, bool> $enabledMap
     * @param array<string, int> $permissionMap
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    public function saveState(array $enabledMap, array $permissionMap, array $permissionBitsMap = []): void
    {
        $currentState = $this->loadStateData();
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

    private function isSafeExtensionDirectoryName(string $name): bool
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
        foreach ($enabledMap as $name => $isEnabled) {
            $directory = (string) $name;
            if ($this->isSafeExtensionDirectoryName($directory) && (bool) $isEnabled) {
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
        foreach ($permissionMap as $name => $rawBit) {
            $directory = (string) $name;
            $bit = (int) $rawBit;
            if ($this->isSafeExtensionDirectoryName($directory) && $bit > 0) {
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
        foreach ($permissionBitsMap as $name => $levelsRaw) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !is_array($levelsRaw)) {
                continue;
            }

            $normalizedLevels = [];
            foreach ($levelsRaw as $levelKey => $rawBit) {
                $level = strtolower(trim((string) $levelKey));
                $bit = (int) $rawBit;
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $level) === 1 && $bit > 0) {
                    $normalizedLevels[$level] = $bit;
                }
            }

            if ($normalizedLevels !== []) {
                ksort($normalizedLevels);
                $normalized[$directory] = $normalizedLevels;
            }
        }

        ksort($normalized);
        return $normalized;
    }
}
