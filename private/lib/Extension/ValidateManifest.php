<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/ValidateManifest.php
 * Validates extension manifest metadata and type/file capability contracts.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Validates extension manifest metadata and type/file capability contracts.
 */
final class ValidateManifest
{
    /**
     * Returns whether a directory name is safe for use as an extension slug.
     *
     * @param string $directoryName Candidate directory name to check.
     * @return bool True when the name matches the slug pattern.
     */
    public function isSafeDirectoryName(string $directoryName): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directoryName) === 1;
    }

    /**
     * Normalizes an extension type string to one of the canonical type tokens.
     *
     * Falls back to `content` when the value is unrecognized.
     *
     * @param string $type Raw type string from ext.json.
     * @return string Canonical type token: helper, content, framework, module, or system.
     */
    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        // Unknown type tokens normalize to `content`.
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            return 'content';
        }

        return $type;
    }

    /**
     * Returns a human-readable error when an extension root violates its type contract, or null on pass.
     *
     * Enforces which provider files (routes_panel.php, routes_public.php, shortcodes.php,
     * fields.php) each extension type is permitted to declare.
     *
     * @param string $extensionRoot Absolute extension directory path.
     * @param string $type Normalized extension type token.
     * @return string|null Contract violation message, or null when the extension passes.
     */
    public function typeContractError(string $extensionRoot, string $type): ?string
    {
        $hasPanelRoutes = Resolver::hasProvider($extensionRoot, 'routes_panel.php');
        $hasPublicRoutes = Resolver::hasProvider($extensionRoot, 'routes_public.php');
        $hasShortcodes = Resolver::hasProvider($extensionRoot, 'shortcodes.php');
        $hasFields = Resolver::hasProvider($extensionRoot, 'fields.php');

        // Framework extensions cannot expose panel route providers.
        if ($hasPanelRoutes && $type === 'framework') {
            return 'Framework extensions may not define routes_panel.php.';
        }

        // Only module extensions may expose public route providers.
        if ($hasPublicRoutes && $type !== 'module') {
            return 'Only module extensions may define routes_public.php.';
        }

        // Shortcodes providers are restricted to content/module types.
        if ($hasShortcodes && !in_array($type, ['content', 'module'], true)) {
            return 'Only content/module extensions may define shortcodes.php.';
        }

        // Fields providers are restricted to content/module types.
        if ($hasFields && !in_array($type, ['content', 'module'], true)) {
            return 'Only content/module extensions may define fields.php.';
        }

        return null;
    }

    /**
     * Reads and lightly normalizes one extension manifest from disk.
     *
     * Returns null when the manifest is missing, malformed, or fails type-contract validation.
     * Callers that need a richer validation payload (e.g. the panel extension catalog) should
     * use `Manager::readManifest()`, which adds permission levels and human-facing metadata.
     *
     * @param string $root Absolute project root path.
     * @param string $directoryName Extension directory name (slug).
     * @return array{name: string, type: string, panel_path: string, panel_section: string, system_extension: bool}|null
     *         Normalized manifest array, or null on validation failure.
     */
    public function readManifest(string $root, string $directoryName): ?array
    {
        // Reject unsafe extension directory names before filesystem access.
        if (!$this->isSafeDirectoryName($directoryName)) {
            return null;
        }

        $manifestPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/ext.json';
        // Missing ext.json means manifest validation fails.
        if (!is_file($manifestPath)) {
            return null;
        }

        $raw = file_get_contents($manifestPath);
        // Empty/unreadable manifest payload is invalid.
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        // Manifest must decode to an associative array/object.
        if (!is_array($decoded)) {
            return null;
        }

        $name = trim((string) ($decoded['name'] ?? ''));
        // Non-empty name is required for valid manifests.
        if ($name === '') {
            return null;
        }

        $slug = strtolower(trim((string) ($decoded['slug'] ?? '')));
        // Slug must be present and match extension slug rules.
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
            return null;
        }

        $type = $this->normalizeType((string) ($decoded['type'] ?? 'content'));

        $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directoryName;
        // Fail when declared providers violate the normalized type contract.
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
