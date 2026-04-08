<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/ExtensionRegistry.php
 * Shared extension state, manifest parsing, and per-request runtime lifecycle.
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
 * Unified extension registry — static metadata surface plus per-request runtime lifecycle.
 *
 * **Static surface** (callable before the autoloader is registered):
 * enabledMap(), permissionMap(), enabledDirectories(), readManifest(), shortcodes(),
 * shortcodesValidationError(), fields(), fieldsValidationError().
 * All manifest reads are memoized in a static cache keyed by "{root}::{directory}" so
 * repeated calls within the same PHP process pay for one filesystem hit per extension.
 *
 * **Instance surface** (used after the autoloader is up, once per request):
 * Instantiate with enabledDirectories() output; the constructor discovers boot providers,
 * storage, and scheduler declarations. Lazy boot/service resolution methods (bootExtension,
 * resolveExtensionServices, extensionContext, bootAllExtensions) are called by raven.php
 * closures and the public/panel bootstrap layers on demand.
 */
final class ExtensionRegistry
{
    // -------------------------------------------------------------------------
    // Static singletons (metadata surface)
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Instance state (runtime lifecycle)
    // -------------------------------------------------------------------------

    private string $root;
    private ExtensionBootstrapContractResolver $bootstrapResolver;

    /**
     * Parsed manifests discovered during construction, keyed by directory name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $extensionManifests = [];

    /**
     * Resolved storage root paths per extension, keyed by directory name.
     *
     * @var array<string, array{local: string, aux: array<string, string>, panel: string, public: string, bin: string}>
     */
    private array $extensionStorage = [];

    /**
     * Boot provider callables keyed by directory name.
     *
     * @var array<string, callable(array<string, mixed>&): void>
     */
    private array $extensionBootProviders = [];

    /**
     * Extension directories that declared scheduler support.
     *
     * @var array<int, string>
     */
    private array $schedulerExtensions = [];

    /**
     * Tracks which extension directories have already been booted this request.
     *
     * @var array<string, bool>
     */
    private array $bootedExtensionDirectories = [];

    /**
     * Per-extension service resolution state machine used to detect and survive circular refs.
     *
     * @var array<string, array{state: string, services: array<string, mixed>}>
     */
    private array $extensionServiceResolutionStates = [];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * Discovers enabled extension boot providers, storage declarations, and scheduler flags.
     *
     * Reads manifests via the static readManifest() cache so this pass is free when
     * enabledDirectories() was already called earlier in the same process.
     *
     * @param string                             $root                        Project root path.
     * @param array<int, string>                 $enabledExtensionDirectories Enabled extension directory names.
     * @param ExtensionBootstrapContractResolver|null $bootstrapResolver       Optional override for testing.
     */
    public function __construct(
        string $root,
        array $enabledExtensionDirectories,
        ?ExtensionBootstrapContractResolver $bootstrapResolver = null
    ) {
        $this->root = rtrim($root, '/');
        $this->bootstrapResolver = $bootstrapResolver ?? new ExtensionBootstrapContractResolver();
        $this->discoverEnabledExtensions($enabledExtensionDirectories);
    }

    // -------------------------------------------------------------------------
    // Static metadata surface
    // -------------------------------------------------------------------------

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
     * constructor — manifests are cached so subsequent callers in the same process do not
     * re-read the files.
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
     * calls within the same PHP process all return from the cache after the first file read.
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
     *   config?: \Raven\Lib\Config\Config
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
     *   config?: \Raven\Lib\Config\Config
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

    // -------------------------------------------------------------------------
    // Instance runtime lifecycle
    // -------------------------------------------------------------------------

    /**
     * Returns enabled extension manifests keyed by extension directory.
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifests(): array
    {
        return $this->extensionManifests;
    }

    /**
     * Returns provisioned extension storage roots keyed by extension directory.
     *
     * @return array<string, array{local: string, aux: array<string, string>, panel: string, public: string, bin: string}>
     */
    public function storageMap(): array
    {
        return $this->extensionStorage;
    }

    /**
     * Returns enabled extension directories that declared scheduler support.
     *
     * @return array<int, string>
     */
    public function schedulerDirectories(): array
    {
        return $this->schedulerExtensions;
    }

    /**
     * Runs one extension bootstrap provider exactly once per request lifecycle.
     *
     * @param array<string, mixed> $rvn       Shared bootstrap container, passed by reference so
     *                                         extension providers can publish services/aliases.
     * @param string               $directory Extension directory name to boot.
     * @return array<string, mixed> Mutated bootstrap container.
     */
    public function bootExtension(array &$rvn, string $directory): array
    {
        $directory = trim($directory);
        if ($directory === '' || isset($this->bootedExtensionDirectories[$directory])) {
            return $rvn;
        }

        $this->bootedExtensionDirectories[$directory] = true;
        $provider = $this->extensionBootProviders[$directory] ?? null;
        if (!is_callable($provider)) {
            return $rvn;
        }

        try {
            $provider($rvn);
        } catch (\Throwable $exception) {
            error_log('Raven extension bootstrap failed for extension "' . $directory . '": ' . $exception->getMessage());
        }

        return $rvn;
    }

    /**
     * Resolves one extension service map, materializing lazy service values on demand.
     *
     * Boots the extension first if not already booted. Memoizes the resolved services
     * back into `$rvn['extension_services'][$directory]` so subsequent calls are free.
     *
     * @param array<string, mixed> $rvn       Shared bootstrap container.
     * @param string               $directory Extension directory name.
     * @return array<string, mixed> Materialized service map for the requested extension.
     */
    public function resolveExtensionServices(array &$rvn, string $directory): array
    {
        $directory = trim($directory);
        if ($directory === '') {
            return [];
        }

        $this->bootExtension($rvn, $directory);

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        if (!is_array($rawExtensionServices)) {
            return [];
        }

        if (!array_key_exists($directory, $rawExtensionServices) || !is_array($rawExtensionServices[$directory])) {
            return [];
        }

        $resolutionState = $this->extensionServiceResolutionStates[$directory] ?? null;
        if (is_array($resolutionState)) {
            $cachedServices = $resolutionState['services'] ?? [];
            if (in_array((string) ($resolutionState['state'] ?? ''), ['resolving', 'resolved'], true) && is_array($cachedServices)) {
                return $cachedServices;
            }
        }

        /** @var array<string, mixed> $services */
        $services = $rawExtensionServices[$directory];
        $this->extensionServiceResolutionStates[$directory] = [
            'state' => 'resolving',
            'services' => $services,
        ];

        try {
            foreach ($services as $serviceKey => &$serviceValue) {
                $this->materializeExtensionValue($serviceValue);
                $services[$serviceKey] = $serviceValue;
                $this->extensionServiceResolutionStates[$directory] = [
                    'state' => 'resolving',
                    'services' => $services,
                ];
            }
            unset($serviceValue);
        } catch (\Throwable $exception) {
            unset($this->extensionServiceResolutionStates[$directory]);
            throw $exception;
        }

        $rawExtensionServices[$directory] = $services;
        $rvn['extension_services'] = $rawExtensionServices;
        $this->extensionServiceResolutionStates[$directory] = [
            'state' => 'resolved',
            'services' => $services,
        ];

        return $services;
    }

    /**
     * Resolves all enabled extension service maps, booting only extensions that
     * actually expose runtime boot providers.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container.
     * @return array<string, array<string, mixed>> Map of directory → materialized service map.
     */
    public function resolveAllExtensionServices(array &$rvn): array
    {
        $services = [];
        foreach (array_keys($this->extensionBootProviders) as $directory) {
            $services[$directory] = $this->resolveExtensionServices($rvn, $directory);
        }

        return $services;
    }

    /**
     * Returns the extension-owned overlay that route registrars should see in
     * their local `$rvn` context.
     *
     * Resolves the extension's services first, then builds an overlay of any
     * keys that the extension boot provider added to `$rvn` beyond the core set.
     * This lets route registrars access their own boot-provided aliases without
     * requiring eager boot of every other extension.
     *
     * @param array<string, mixed> $rvn              Shared bootstrap container.
     * @param string               $directory        Extension directory name.
     * @param array<string, bool>  $coreContainerKeys Keys owned by core bootstrap itself; excluded from overlay.
     * @return array<string, mixed> Booted extension overlay plus current extension service map.
     */
    public function extensionContext(array &$rvn, string $directory, array $coreContainerKeys): array
    {
        $directory = trim($directory);
        if ($directory === '') {
            return [];
        }

        $this->resolveExtensionServices($rvn, $directory);

        $overlay = [];
        foreach ($rvn as $key => $value) {
            if ($key === 'extension_services') {
                if (is_array($value) && array_key_exists($directory, $value)) {
                    $overlay[$key] = $value;
                }
                continue;
            }

            if (isset($coreContainerKeys[$key])) {
                continue;
            }

            $overlay[$key] = $value;
        }

        return $overlay;
    }

    /**
     * Boots every enabled extension provider.
     *
     * Preserved for maintenance/debug paths that want the full extension graph
     * materialized eagerly rather than on demand.
     *
     * @param array<string, mixed> $rvn Shared bootstrap container.
     * @return array<string, mixed> Mutated bootstrap container.
     */
    public function bootAllExtensions(array &$rvn): array
    {
        foreach (array_keys($this->extensionBootProviders) as $directory) {
            $this->bootExtension($rvn, $directory);
        }

        return $rvn;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Discovers enabled extension boot providers, storage declarations, and scheduler flags.
     *
     * Uses static readManifest() so this pass is free when enabledDirectories() was already
     * called earlier in the same process — both hit the same static manifest cache.
     *
     * @param array<int, string> $enabledExtensionDirectories Enabled extension directory names.
     * @return void
     */
    private function discoverEnabledExtensions(array $enabledExtensionDirectories): void
    {
        foreach ($enabledExtensionDirectories as $directory) {
            // self::readManifest() hits the static cache — no filesystem hit if
            // enabledDirectories() was called before constructing this instance.
            $manifest = self::readManifest($this->root, $directory);
            if (!is_array($manifest)) {
                continue;
            }

            $this->extensionManifests[$directory] = $manifest;

            $bootstrap = $this->bootstrapResolver->resolve($this->root, $directory, $manifest);
            if (!$bootstrap['valid']) {
                error_log('Raven extension bootstrap is invalid for extension "' . $directory . '": ' . (string) ($bootstrap['error'] ?? 'Unknown error.'));
                continue;
            }

            if (!empty($bootstrap['scheduler'])) {
                $this->schedulerExtensions[] = $directory;
            }

            $storage = is_array($bootstrap['storage'] ?? null) ? (array) $bootstrap['storage'] : [];
            $this->extensionStorage[$directory] = [
                'local'  => !empty($storage['local']) ? ($this->root . '/private/dat/ext/' . $directory) : '',
                'aux'    => [],
                'panel'  => !empty($storage['panel']) ? ($this->root . '/panel/ext/' . $directory) : '',
                'public' => !empty($storage['public']) ? ($this->root . '/public/uploads/ext/' . $directory) : '',
                // Bin storage resolves to the extension's own bin/ directory; private/bin/ contains the symlinks.
                'bin'    => !empty($storage['bin']) ? ($this->root . '/private/ext/' . $directory . '/bin') : '',
            ];

            foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
                if (!is_string($auxDirectory) || $auxDirectory === '') {
                    continue;
                }

                $this->extensionStorage[$directory]['aux'][$auxDirectory] = $this->root . '/' . $auxDirectory;
            }

            $provider = $bootstrap['boot'] ?? null;
            if (is_callable($provider)) {
                $this->extensionBootProviders[$directory] = $provider;
            }
        }
    }

    /**
     * Resolves nested extension-service values and memoizes any closure output
     * back into the original array structure.
     *
     * @param mixed $value Extension service value or nested service payload; modified in place.
     * @return mixed Materialized service value.
     */
    private function materializeExtensionValue(mixed &$value): mixed
    {
        if ($value instanceof \Closure) {
            $value = $value();
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => &$childValue) {
            $this->materializeExtensionValue($childValue);
            $value[$key] = $childValue;
        }
        unset($childValue);

        return $value;
    }

    /**
     * Loads + validates one extension shortcode provider file.
     *
     * @param string $root          Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Lib\Config\Config
     * } $context
     * @return array{valid: bool, error: string, items: array<int, array{label: string, shortcode: string}>}
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
     * @return array{valid: bool, error: string, items: array<int, array{slug: string, label: string, editor: string}>}
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
