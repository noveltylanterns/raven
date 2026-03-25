<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Validates extension manifest metadata and type/file capability contracts.
 */
final class ManifestContractValidator
{
    public function isSafeDirectoryName(string $directoryName): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directoryName) === 1;
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            return 'plugin';
        }

        return $type;
    }

    public function normalizeStorageFlag(mixed $value): ?string
    {
        if ($value === null) {
            return 'off';
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['on', 'off'], true)) {
            return null;
        }

        return $normalized;
    }

    public function storageEnabled(mixed $value): ?bool
    {
        $normalized = $this->normalizeStorageFlag($value);
        if ($normalized === null) {
            return null;
        }

        return $normalized === 'on';
    }

    public function typeContractError(string $extensionRoot, string $type): ?string
    {
        $hasPublicRoutes = is_file($extensionRoot . '/lib/routes_public.php');
        $hasShortcodes = is_file($extensionRoot . '/lib/shortcodes.php');
        $hasFields = is_file($extensionRoot . '/lib/fields.php');

        if ($hasPublicRoutes && $type !== 'module') {
            return 'Only module extensions may define lib/routes_public.php.';
        }

        if ($hasShortcodes && !in_array($type, ['helper', 'plugin', 'module'], true)) {
            return 'Only helper/plugin/module extensions may define lib/shortcodes.php.';
        }

        if ($hasFields && !in_array($type, ['content', 'plugin', 'module'], true)) {
            return 'Only content/plugin/module extensions may define lib/fields.php.';
        }

        return null;
    }

    /**
     * @return array{
     *   name: string,
     *   type: string,
     *   panel_path: string,
     *   panel_section: string,
     *   system_extension: bool,
     *   local_storage: bool,
     *   db_storage: bool
     * }|null
     */
    public function readManifest(string $root, string $directoryName): ?array
    {
        if (!$this->isSafeDirectoryName($directoryName)) {
            return null;
        }

        $manifestPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/ext.json';
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

        $slug = strtolower(trim((string) ($decoded['slug'] ?? '')));
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
            return null;
        }

        $type = $this->normalizeType((string) ($decoded['type'] ?? 'plugin'));
        $localStorage = $this->storageEnabled($decoded['local_storage'] ?? null);
        $dbStorage = $this->storageEnabled($decoded['db_storage'] ?? null);
        if ($localStorage === null || $dbStorage === null) {
            return null;
        }

        $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directoryName;
        if ($this->typeContractError($extensionRoot, $type) !== null) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $type,
            // Extension routing identity is standardized on directory slug.
            'panel_path' => $directoryName,
            'panel_section' => $directoryName,
            'system_extension' => (bool) ($decoded['system_extension'] ?? false),
            'local_storage' => $localStorage,
            'db_storage' => $dbStorage,
        ];
    }
}
