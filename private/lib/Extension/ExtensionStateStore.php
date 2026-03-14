<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Shared persistence service for extension enablement/permission state maps.
 */
final class ExtensionStateStore
{
    private string $extensionsBasePath;

    public function __construct(string $extensionsBasePath)
    {
        $this->extensionsBasePath = rtrim($extensionsBasePath, '/\\');
    }

    public function basePath(): string
    {
        return $this->extensionsBasePath;
    }

    public function stateFilePath(): string
    {
        return $this->extensionsBasePath . '/.state.php';
    }

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
            return [
                'enabled' => [],
                'permissions' => [],
                'permission_bits' => [],
            ];
        }

        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        /** @var mixed $loaded */
        $loaded = require $statePath;
        if (!is_array($loaded)) {
            return [
                'enabled' => [],
                'permissions' => [],
                'permission_bits' => [],
            ];
        }

        /** @var mixed $rawEnabled */
        $rawEnabled = array_key_exists('enabled', $loaded) ? $loaded['enabled'] : $loaded;
        if (!array_key_exists('enabled', $loaded) && array_key_exists('permissions', $loaded)) {
            $rawEnabled = [];
        }
        if (!is_array($rawEnabled)) {
            $rawEnabled = [];
        }

        /** @var mixed $rawPermissions */
        $rawPermissions = $loaded['permissions'] ?? [];
        if (!is_array($rawPermissions)) {
            $rawPermissions = [];
        }

        /** @var mixed $rawPermissionBits */
        $rawPermissionBits = $loaded['permission_bits'] ?? [];
        if (!is_array($rawPermissionBits)) {
            $rawPermissionBits = [];
        }

        $enabled = [];
        foreach ($rawEnabled as $name => $isEnabled) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            if ((bool) $isEnabled) {
                $enabled[$directory] = true;
            }
        }

        $permissions = [];
        foreach ($rawPermissions as $name => $rawBit) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            $bit = (int) $rawBit;
            if ($bit <= 0) {
                continue;
            }

            $permissions[$directory] = $bit;
        }

        $permissionBits = [];
        foreach ($rawPermissionBits as $name => $levelsRaw) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !is_array($levelsRaw)) {
                continue;
            }

            $normalizedLevels = [];
            foreach ($levelsRaw as $levelKey => $rawBit) {
                $level = strtolower(trim((string) $levelKey));
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $level) !== 1) {
                    continue;
                }

                $bit = (int) $rawBit;
                if ($bit <= 0) {
                    continue;
                }

                $normalizedLevels[$level] = $bit;
            }

            if ($normalizedLevels === []) {
                continue;
            }

            ksort($normalizedLevels);
            $permissionBits[$directory] = $normalizedLevels;
        }

        ksort($enabled);
        ksort($permissions);
        ksort($permissionBits);

        return [
            'enabled' => $enabled,
            'permissions' => $permissions,
            'permission_bits' => $permissionBits,
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
        $permissionMap = $this->loadPermissionMap();
        $permissionBitsMap = $this->loadPermissionBitsMap();
        $this->saveState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * @param array<string, int> $permissionMap
     */
    public function savePermissionMap(array $permissionMap): void
    {
        $enabledMap = $this->loadEnabledMap();
        $permissionBitsMap = $this->loadPermissionBitsMap();
        $this->saveState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    public function savePermissionBitsMap(array $permissionBitsMap): void
    {
        $enabledMap = $this->loadEnabledMap();
        $permissionMap = $this->loadPermissionMap();
        $this->saveState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * @param array<string, bool> $enabledMap
     * @param array<string, int> $permissionMap
     * @param array<string, array<string, int>> $permissionBitsMap
     */
    public function saveState(array $enabledMap, array $permissionMap, array $permissionBitsMap = []): void
    {
        if ($permissionBitsMap === []) {
            $permissionBitsMap = $this->loadPermissionBitsMap();
        }

        $filteredEnabled = [];
        foreach ($enabledMap as $name => $isEnabled) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !$isEnabled) {
                continue;
            }

            $filteredEnabled[$directory] = true;
        }
        ksort($filteredEnabled);

        $filteredPermissions = [];
        foreach ($permissionMap as $name => $rawBit) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory)) {
                continue;
            }

            $bit = (int) $rawBit;
            if ($bit <= 0) {
                continue;
            }

            $filteredPermissions[$directory] = $bit;
        }
        ksort($filteredPermissions);

        $filteredPermissionBits = [];
        foreach ($permissionBitsMap as $name => $levelsRaw) {
            $directory = (string) $name;
            if (!$this->isSafeExtensionDirectoryName($directory) || !is_array($levelsRaw)) {
                continue;
            }

            $normalizedLevels = [];
            foreach ($levelsRaw as $levelKey => $rawBit) {
                $level = strtolower(trim((string) $levelKey));
                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $level) !== 1) {
                    continue;
                }

                $bit = (int) $rawBit;
                if ($bit <= 0) {
                    continue;
                }

                $normalizedLevels[$level] = $bit;
            }

            if ($normalizedLevels === []) {
                continue;
            }

            ksort($normalizedLevels);
            $filteredPermissionBits[$directory] = $normalizedLevels;
        }
        ksort($filteredPermissionBits);

        $export = var_export([
            'enabled' => $filteredEnabled,
            'permissions' => $filteredPermissions,
            'permission_bits' => $filteredPermissionBits,
        ], true);
        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * RAVEN CMS\n";
        $content .= " * ~/private/ext/.state.php\n";
        $content .= " * Persisted extension enablement map and permission settings managed by panel.\n";
        $content .= " * Docs: https://raven.lanterns.io\n";
        $content .= " */\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return " . $export . ";\n";

        $written = file_put_contents($this->stateFilePath(), $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to persist extension state.');
        }

        $statePath = $this->stateFilePath();
        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }
    }

    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }
}
