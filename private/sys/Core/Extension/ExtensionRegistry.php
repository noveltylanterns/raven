<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Extension/ExtensionRegistry.php
 * Shared extension state and manifest parsing helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Extension;

require_once dirname(__DIR__, 3) . '/lib/Extension/ManifestContractValidator.php';
require_once dirname(__DIR__, 3) . '/lib/Extension/ExtensionProviderValidator.php';
require_once dirname(__DIR__, 3) . '/lib/Extension/ExtensionStateLoader.php';

use Raven\Lib\Extension\ExtensionProviderValidator;
use Raven\Lib\Extension\ManifestContractValidator;
use Raven\Lib\Extension\ExtensionStateLoader;

/**
 * Centralizes extension registry parsing so bootstrap/panel/public stay in sync.
 */
final class ExtensionRegistry
{
    private static ?ManifestContractValidator $manifestContractValidator = null;
    private static ?ExtensionProviderValidator $providerValidator = null;
    private static ?ExtensionStateLoader $stateLoader = null;
    /**
     * Returns enabled extension directory map from `private/dat/ext/.state.php`
     * with a legacy fallback to `private/ext/.state.php`.
     *
     * @return array<string, bool>
     */
    public static function enabledMap(string $root): array
    {
        $state = self::stateLoader()->loadState($root);
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
     * Returns extension permission-bit map from `private/dat/ext/.state.php`
     * with a legacy fallback to `private/ext/.state.php`.
     *
     * @param array<int, int> $allowedBits
     * @return array<string, int>
     */
    public static function permissionMap(string $root, array $allowedBits = []): array
    {
        $state = self::stateLoader()->loadState($root);
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
     *   system_extension: bool,
     *   local_storage: bool,
     *   db_storage: bool
     * }|null
     */
    public static function readManifest(string $root, string $directoryName): ?array
    {
        $manifest = self::manifestContractValidator()->readManifest($root, $directoryName);
        if ($manifest === null) {
            return null;
        }

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

        return $manifest;
    }

    /**
     * Returns normalized extension shortcode items from `private/ext/{slug}/lib/shortcodes.php`.
     *
     * Missing provider file is valid and returns an empty list.
     * Invalid providers return null.
     *
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Core\Config
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
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Core\Config
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
     * Loads + validates one extension shortcode provider file.
     *
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
        return self::providerValidator()->validateFieldsProvider($root, $directoryName, $context);
    }

    private static function manifestContractValidator(): ManifestContractValidator
    {
        if (!self::$manifestContractValidator instanceof ManifestContractValidator) {
            self::$manifestContractValidator = new ManifestContractValidator();
        }

        return self::$manifestContractValidator;
    }

    private static function providerValidator(): ExtensionProviderValidator
    {
        if (!self::$providerValidator instanceof ExtensionProviderValidator) {
            self::$providerValidator = new ExtensionProviderValidator(self::manifestContractValidator());
        }

        return self::$providerValidator;
    }

    private static function stateLoader(): ExtensionStateLoader
    {
        if (!self::$stateLoader instanceof ExtensionStateLoader) {
            self::$stateLoader = new ExtensionStateLoader();
        }

        return self::$stateLoader;
    }
}
