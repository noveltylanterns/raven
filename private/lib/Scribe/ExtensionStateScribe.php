<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/ExtensionStateScribe.php
 * Write-side persistence helper for extension enablement and permission state.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Raven\Lib\Database\Schema\SchemaEnsureStateStore;

/**
 * Owns filesystem writes for `private/dat/ext/.state.php`.
 *
 * ExtensionStateStore keeps the read-side state loading helpers, while this
 * class centralizes normalization, serialization, atomic persistence, and
 * schema-marker invalidation for extension enablement and permission writes.
 */
final class ExtensionStateScribe
{
    private string $extensionsBasePath;
    private string $stateBasePath;

    /**
     * Prepares the extension-state scribe for one project tree.
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
    }

    /**
     * Ensures the extension-state directory exists before persistence.
     *
     * @return void
     */
    public function ensureStateDirectory(): void
    {
        if (is_dir($this->stateBasePath)) {
            return;
        }

        if (!mkdir($this->stateBasePath, 0775, true) && !is_dir($this->stateBasePath)) {
            throw new \RuntimeException('Failed to create private/dat/ext directory.');
        }
    }

    /**
     * Persists the full extension enablement/permission state map.
     *
     * @param array<string, bool> $enabledMap Enabled extension map.
     * @param array<string, int> $permissionMap Required permission-bit map.
     * @param array<string, array<string, int>> $permissionBitsMap Level-to-bit map.
     * @param array<string, bool> $previousEnabledMap Previously loaded enabled map used for schema invalidation checks.
     * @return void
     */
    public function saveState(
        array $enabledMap,
        array $permissionMap,
        array $permissionBitsMap,
        array $previousEnabledMap = []
    ): void {
        $filteredEnabled = $this->normalizeEnabledMap($enabledMap);
        $filteredPermissions = $this->normalizePermissionMap($permissionMap);
        $filteredPermissionBits = $this->normalizePermissionBitsMap($permissionBitsMap);

        $export = var_export([
            'enabled' => $filteredEnabled,
            'permissions' => $filteredPermissions,
            'permission_bits' => $filteredPermissionBits,
        ], true);
        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * RAVEN CMS\n";
        $content .= " * ~/private/dat/ext/.state.php\n";
        $content .= " * Persisted extension enablement map and permission settings managed by panel.\n";
        $content .= " * Docs: https://raven.lanterns.io\n";
        $content .= " */\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return " . $export . ";\n";

        $this->ensureStateDirectory();
        $statePath = $this->stateFilePath();
        $written = file_put_contents($statePath, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to persist extension state.');
        }

        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        if ($this->normalizeEnabledMap($previousEnabledMap) !== $filteredEnabled) {
            $this->schemaEnsureStateStore()->invalidate();
        }
    }

    /**
     * Resolves the canonical extension-state file path.
     *
     * @return string Absolute path to `private/dat/ext/.state.php`.
     */
    private function stateFilePath(): string
    {
        return $this->stateBasePath . '/.state.php';
    }

    /**
     * Returns the schema ensure marker helper for the current project root.
     *
     * @return SchemaEnsureStateStore Shared schema ensure marker helper.
     */
    private function schemaEnsureStateStore(): SchemaEnsureStateStore
    {
        return new SchemaEnsureStateStore(dirname($this->extensionsBasePath, 2));
    }

    /**
     * Returns whether one extension directory token is safe for persistence.
     *
     * @param string $name Candidate extension directory token.
     * @return bool True when the token matches Raven's extension-slug rules.
     */
    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
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
