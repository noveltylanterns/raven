<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Extension/ExtensionRegistry.php
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

        $type = strtolower(trim((string) ($decoded['type'] ?? 'plugin')));
        if (!in_array($type, ['helper', 'content', 'plugin', 'module', 'system'], true)) {
            $type = 'plugin';
        }

        $typeContractError = self::typeContractError($root, $directoryName, $type);
        if ($typeContractError !== null) {
            return null;
        }

        // Extension routing identity is standardized on directory slug.
        $panelPath = $directoryName;
        $panelSection = $directoryName;

        // `lib/shortcodes.php` is optional, but when present it must return the canonical
        // shortcode item format so extension behavior stays deterministic.
        if (self::shortcodesValidationError($root, $directoryName) !== null) {
            return null;
        }

        // `lib/fields.php` is optional, but when present it must return canonical
        // body-block field definitions.
        if (self::fieldsValidationError($root, $directoryName) !== null) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $type,
            'panel_path' => $panelPath,
            'panel_section' => $panelSection,
            'system_extension' => (bool) ($decoded['system_extension'] ?? false),
        ];
    }

    /**
     * Returns one type-capability contract violation for extension files.
     */
    private static function typeContractError(string $root, string $directoryName, string $type): ?string
    {
        $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directoryName;
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
     * Returns normalized extension shortcode items from `private/ext/{slug}/lib/shortcodes.php`.
     *
     * Missing provider file is valid and returns an empty list.
     * Invalid providers return null.
     *
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>
     * } $context
     * @return array<int, array{label: string, shortcode: string}>|null
     */
    public static function shortcodes(string $root, string $directoryName, array $context = []): ?array
    {
        $validation = self::validateShortcodesProvider($root, $directoryName, $context);
        if (!$validation['valid']) {
            return null;
        }

        return $validation['items'];
    }

    /**
     * Returns one provider-validation error when `lib/shortcodes.php` is invalid.
     *
     * Missing provider file is treated as valid and returns null.
     *
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>
     * } $context
     */
    public static function shortcodesValidationError(string $root, string $directoryName, array $context = []): ?string
    {
        $validation = self::validateShortcodesProvider($root, $directoryName, $context);
        return $validation['valid'] ? null : $validation['error'];
    }

    /**
     * Returns normalized extension field items from `private/ext/{slug}/lib/fields.php`.
     *
     * Missing provider file is valid and returns an empty list.
     * Invalid providers return null.
     *
     * @param array{extension?: string} $context
     * @return array<int, array{slug: string, label: string, editor: string}>|null
     */
    public static function fields(string $root, string $directoryName, array $context = []): ?array
    {
        $validation = self::validateFieldsProvider($root, $directoryName, $context);
        if (!$validation['valid']) {
            return null;
        }

        return $validation['items'];
    }

    /**
     * Returns one provider-validation error when `lib/fields.php` is invalid.
     *
     * Missing provider file is treated as valid and returns null.
     *
     * @param array{extension?: string} $context
     */
    public static function fieldsValidationError(string $root, string $directoryName, array $context = []): ?string
    {
        $validation = self::validateFieldsProvider($root, $directoryName, $context);
        return $validation['valid'] ? null : $validation['error'];
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

    /**
     * Loads + validates one extension shortcode provider file.
     *
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>
     * } $context
     * @return array{
     *   valid: bool,
     *   error: string,
     *   items: array<int, array{label: string, shortcode: string}>
     * }
     */
    private static function validateShortcodesProvider(string $root, string $directoryName, array $context): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directoryName) !== 1) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'items' => [],
            ];
        }

        $providerPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/lib/shortcodes.php';
        if (!is_file($providerPath)) {
            return [
                'valid' => true,
                'error' => '',
                'items' => [],
            ];
        }

        /** @var mixed $provider */
        try {
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'lib/shortcodes.php threw an error while loading.',
                'items' => [],
            ];
        }

        if (is_callable($provider)) {
            $context['extension'] = $directoryName;
            if (!isset($context['forms']) || !is_callable($context['forms'])) {
                $context['forms'] = static fn (string $tableName): array => [];
            }

            try {
                $provider = $provider($context);
            } catch (\ArgumentCountError) {
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    return [
                        'valid' => false,
                        'error' => 'lib/shortcodes.php callable threw an error while executing.',
                        'items' => [],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'valid' => false,
                    'error' => 'lib/shortcodes.php callable threw an error while executing.',
                    'items' => [],
                ];
            }
        }

        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'lib/shortcodes.php must return an array (or callable returning an array).',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($provider as $entry) {
            if (!is_array($entry)) {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode row must be an object-like array.',
                    'items' => [],
                ];
            }

            $label = trim((string) ($entry['label'] ?? ''));
            $shortcode = trim((string) ($entry['shortcode'] ?? ''));
            $shortcode = str_replace(["\r", "\n", "\0"], '', $shortcode);
            if ($label === '' || $shortcode === '') {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode row must include non-empty "label" and "shortcode" values.',
                    'items' => [],
                ];
            }

            if (!str_starts_with($shortcode, '[') || !str_ends_with($shortcode, ']')) {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode value must use bracket shortcode syntax (for example `[example]`).',
                    'items' => [],
                ];
            }

            $items[] = [
                'label' => $label,
                'shortcode' => $shortcode,
            ];
        }

        return [
            'valid' => true,
            'error' => '',
            'items' => $items,
        ];
    }

    /**
     * Loads + validates one extension field provider file.
     *
     * Universal row format:
     * - slug: string
     * - label: string
     * - editor: tinymce|plaintext|autobr|markdown|markdown_file
     *
     * @param array{extension?: string} $context
     * @return array{
     *   valid: bool,
     *   error: string,
     *   items: array<int, array{slug: string, label: string, editor: string}>
     * }
     */
    private static function validateFieldsProvider(string $root, string $directoryName, array $context): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directoryName) !== 1) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'items' => [],
            ];
        }

        $providerPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/lib/fields.php';
        if (!is_file($providerPath)) {
            return [
                'valid' => true,
                'error' => '',
                'items' => [],
            ];
        }

        /** @var mixed $provider */
        try {
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'lib/fields.php threw an error while loading.',
                'items' => [],
            ];
        }

        if (is_callable($provider)) {
            $context['extension'] = $directoryName;

            try {
                $provider = $provider($context);
            } catch (\ArgumentCountError) {
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    return [
                        'valid' => false,
                        'error' => 'lib/fields.php callable threw an error while executing.',
                        'items' => [],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'valid' => false,
                    'error' => 'lib/fields.php callable threw an error while executing.',
                    'items' => [],
                ];
            }
        }

        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'lib/fields.php must return an array (or callable returning an array).',
                'items' => [],
            ];
        }

        $allowedEditors = ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file'];
        $items = [];
        $seenSlugs = [];
        foreach ($provider as $entry) {
            if (!is_array($entry)) {
                return [
                    'valid' => false,
                    'error' => 'Each fields row must be an object-like array.',
                    'items' => [],
                ];
            }

            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            $label = trim((string) ($entry['label'] ?? ''));
            $editor = strtolower(trim((string) ($entry['editor'] ?? '')));

            if ($slug === '' || $label === '' || $editor === '') {
                return [
                    'valid' => false,
                    'error' => 'Each fields row must include non-empty "slug", "label", and "editor" values.',
                    'items' => [],
                ];
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                return [
                    'valid' => false,
                    'error' => 'Field slug values must match `[a-z0-9][a-z0-9_-]*`.',
                    'items' => [],
                ];
            }
            if (!in_array($editor, $allowedEditors, true)) {
                return [
                    'valid' => false,
                    'error' => 'Field editor values must be one of: tinymce, plaintext, autobr, markdown, markdown_file.',
                    'items' => [],
                ];
            }
            if (isset($seenSlugs[$slug])) {
                return [
                    'valid' => false,
                    'error' => 'Field slugs must be unique within lib/fields.php.',
                    'items' => [],
                ];
            }
            $seenSlugs[$slug] = true;

            $items[] = [
                'slug' => $slug,
                'label' => $label,
                'editor' => $editor,
            ];
        }

        return [
            'valid' => true,
            'error' => '',
            'items' => $items,
        ];
    }
}
