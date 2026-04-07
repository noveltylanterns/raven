<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/ExtensionRegistry.php
 * Shared extension state and manifest parsing helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

// Loaded via require_once so this file can be included before the PSR-4 autoloader
// is registered (raven.php bootstrap calls enabledDirectories() to build the autoloader).
require_once __DIR__ . '/ManifestContractValidator.php';
require_once __DIR__ . '/ExtensionProviderValidator.php';
require_once __DIR__ . '/ExtensionStateStore.php';

/**
 * Centralizes extension registry parsing so bootstrap, panel, and public stay in sync.
 *
 * All metadata reads are memoized in static caches for the lifetime of the PHP process.
 * This prevents the triple manifest-read pattern that previously occurred when
 * enabledDirectories() was called at bootstrap (autoloader setup), again in
 * ExtensionRuntimeRegistry::discoverEnabledExtensions(), and again in
 * SchemaEnsureStateStore::signature().
 */
final class ExtensionRegistry
{
    private static ?ManifestContractValidator $manifestContractValidator = null;
    private static ?ExtensionProviderValidator $providerValidator = null;
    private static ?ExtensionStateStore $stateStore = null;

    /**
     * Per-process manifest cache keyed by "{root}::{directory}".
     *
     * Stores the parsed manifest array on success, or null on failure. Uses
     * array_key_exists() rather than isset() so cached nulls are also hits.
     *
     * @var array<string, array{name: string, type: string, panel_path: string, panel_section: string, system_extension: bool}|null>
     */
    private static array $manifestCache = [];

    /**
     * Returns enabled extension directory map from `private/dat/ext/.state.php`.
     *
     * @param string $root Project root path.
     * @return array<string, bool> Map of directory name → true for all enabled extensions.
     */
    public static function enabledMap(string $root): array
    {
        $state = self::stateStore($root)->loadStateData();
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
     * Returns extension permission-bit map from `private/dat/ext/.state.php`.
     *
     * @param string      $root        Project root path.
     * @param array<int, int> $allowedBits When non-empty, only entries whose bit is in this list are returned.
     * @return array<string, int> Map of directory name → permission bit for enabled extensions.
     */
    public static function permissionMap(string $root, array $allowedBits = []): array
    {
        $state = self::stateStore($root)->loadStateData();
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
     * When `$requireValidManifest` is true (default), only directories whose `ext.json`
     * passes manifest validation are included. Results feed the PSR-4 autoloader and the
     * runtime registry — manifests are cached so subsequent callers in the same process
     * do not re-read the files.
     *
     * @param string $root                Project root path.
     * @param bool   $requireValidManifest When true, skips extensions with invalid manifests.
     * @return array<int, string> Ordered list of valid enabled extension directory names.
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
     * Results are cached in a static array keyed by "{root}::{directory}" so repeated
     * calls within the same PHP process (bootstrap autoloader, runtime registry,
     * schema state store) all return from the cache after the first file read.
     * Null (invalid manifest) is cached the same way as a valid result.
     *
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name (slug).
     * @return array{name: string, type: string, panel_path: string, panel_section: string, system_extension: bool}|null
     *         Normalized manifest array, or null when the manifest is missing or invalid.
     */
    public static function readManifest(string $root, string $directoryName): ?array
    {
        $cacheKey = $root . '::' . $directoryName;
        if (array_key_exists($cacheKey, self::$manifestCache)) {
            return self::$manifestCache[$cacheKey];
        }

        $manifest = self::manifestContractValidator()->readManifest($root, $directoryName);
        if ($manifest === null) {
            self::$manifestCache[$cacheKey] = null;
            return null;
        }

        // `lib/shortcodes.php` is optional, but when present it must return the canonical
        // shortcode item format so extension behavior stays deterministic.
        if (self::shortcodesValidationError($root, $directoryName) !== null) {
            self::$manifestCache[$cacheKey] = null;
            return null;
        }

        // `lib/fields.php` is optional, but when present it must return canonical
        // body-block field definitions.
        if (self::fieldsValidationError($root, $directoryName) !== null) {
            self::$manifestCache[$cacheKey] = null;
            return null;
        }

        self::$manifestCache[$cacheKey] = $manifest;
        return $manifest;
    }

    /**
     * Returns normalized extension shortcode items from `private/ext/{slug}/lib/shortcodes.php`.
     *
     * Missing provider file is valid and returns an empty list.
     * Invalid providers return null.
     *
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Core\Config
     * } $context Optional context passed to the shortcode provider.
     * @return array<int, array{label: string, shortcode: string}>|null
     *         Normalized shortcode items, or null when the provider is invalid.
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
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Core\Config
     * } $context Optional context passed to the shortcode provider.
     * @return string|null Validation error message, or null when the provider is valid.
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
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{extension?: string} $context Optional context passed to the fields provider.
     * @return array<int, array{slug: string, label: string, editor: string}>|null
     *         Normalized field items, or null when the provider is invalid.
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
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{extension?: string} $context Optional context passed to the fields provider.
     * @return string|null Validation error message, or null when the provider is valid.
     */
    public static function fieldsValidationError(string $root, string $directoryName, array $context = []): ?string
    {
        $validation = self::validateFieldsProvider($root, $directoryName, $context);
        return $validation['valid'] ? null : $validation['error'];
    }

    /**
     * Loads + validates one extension shortcode provider file.
     *
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Core\Config
     * } $context
     * @return array{
     *   valid: bool,
     *   error: string,
     *   items: array<int, array{label: string, shortcode: string}>
     * }
     */
    private static function validateShortcodesProvider(string $root, string $directoryName, array $context): array
    {
        return self::providerValidator()->validateShortcodesProvider($root, $directoryName, $context);
    }

    /**
     * Loads + validates one extension field provider file.
     *
     * Universal row format: slug (string), label (string), editor (string enum).
     *
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{extension?: string} $context
     * @return array{
     *   valid: bool,
     *   error: string,
     *   items: array<int, array{slug: string, label: string, editor: string}>
     * }
     */
    private static function validateFieldsProvider(string $root, string $directoryName, array $context): array
    {
        return self::providerValidator()->validateFieldsProvider($root, $directoryName, $context);
    }

    /**
     * Returns the singleton ManifestContractValidator instance.
     *
     * @return ManifestContractValidator
     */
    private static function manifestContractValidator(): ManifestContractValidator
    {
        if (!self::$manifestContractValidator instanceof ManifestContractValidator) {
            self::$manifestContractValidator = new ManifestContractValidator();
        }

        return self::$manifestContractValidator;
    }

    /**
     * Returns the singleton ExtensionProviderValidator instance.
     *
     * @return ExtensionProviderValidator
     */
    private static function providerValidator(): ExtensionProviderValidator
    {
        if (!self::$providerValidator instanceof ExtensionProviderValidator) {
            self::$providerValidator = new ExtensionProviderValidator(self::manifestContractValidator());
        }

        return self::$providerValidator;
    }

    /**
     * Returns the singleton ExtensionStateStore instance for the given root.
     *
     * The state store is keyed to the project root; swapping root mid-process
     * (only in tests) produces a fresh instance.
     *
     * @param string $root Project root path.
     * @return ExtensionStateStore
     */
    private static function stateStore(string $root): ExtensionStateStore
    {
        if (!self::$stateStore instanceof ExtensionStateStore) {
            // Auto-derives stateBasePath as private/dat/ext from extensionsBasePath.
            self::$stateStore = new ExtensionStateStore($root . '/private/ext');
        }

        return self::$stateStore;
    }
}
