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
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            return 'content';
        }

        return $type;
    }

    public function typeContractError(string $extensionRoot, string $type): ?string
    {
        $hasPanelRoutes = is_file($extensionRoot . '/lib/routes_panel.php');
        $hasPublicRoutes = is_file($extensionRoot . '/lib/routes_public.php');
        $hasShortcodes = is_file($extensionRoot . '/lib/shortcodes.php');
        $hasFields = is_file($extensionRoot . '/lib/fields.php');

        if ($hasPanelRoutes && $type === 'framework') {
            return 'Framework extensions may not define lib/routes_panel.php.';
        }

        if ($hasPublicRoutes && $type !== 'module') {
            return 'Only module extensions may define lib/routes_public.php.';
        }

        if ($hasShortcodes && !in_array($type, ['content', 'module'], true)) {
            return 'Only content/module extensions may define lib/shortcodes.php.';
        }

        if ($hasFields && !in_array($type, ['content', 'module'], true)) {
            return 'Only content/module extensions may define lib/fields.php.';
        }

        return null;
    }

    /**
     * @return array{
     *   name: string,
     *   type: string,
     *   panel_path: string,
     *   panel_section: string,
     *   system_extension: bool
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

        $type = $this->normalizeType((string) ($decoded['type'] ?? 'content'));

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
        ];
    }
}
