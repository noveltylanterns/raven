<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Registry.php
 * Shared extension state, manifest parsing, and per-request runtime lifecycle.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

// Loaded via require_once so this file can be included before the PSR-4 autoloader
// is registered (raven.php bootstrap calls enabledDirectories() to build the autoloader).
require_once __DIR__ . '/ValidateManifest.php';
require_once __DIR__ . '/ValidateProvider.php';
require_once __DIR__ . '/StateRead.php';

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
final class Registry
{
    // -------------------------------------------------------------------------
    // Static singletons (metadata surface)
    // -------------------------------------------------------------------------

    private static ?ValidateManifest $validateManifest = null;
    private static ?ValidateProvider $validateProvider = null;
    /**
     * Per-process extension-state store cache keyed by project root.
     *
     * Manifest metadata is already memoized per root, so the state-store seam
     * should follow the same rule instead of assuming one project tree per PHP
     * process forever. This keeps long-lived tests and tooling flows from
     * accidentally reusing the wrong `private/ext` / `private/dat/ext` paths.
     *
     * @var array<string, StateRead>
     */
    private static array $stateReads = [];

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
    private Bootstrap $bootstrapResolver;

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
     * @param Bootstrap|null $bootstrapResolver       Optional override for testing.
     */
    public function __construct(
        string $root,
        array $enabledExtensionDirectories,
        ?Bootstrap $bootstrapResolver = null
    ) {
        $this->root = rtrim($root, '/');
        $this->bootstrapResolver = $bootstrapResolver ?? new Bootstrap();
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
        $state = self::stateRead($root)->loadStateData();
        /** @var mixed $rawEnabled */
        $rawEnabled = $state['enabled'] ?? [];
        // Enabled-state payload must be an associative array map.
        if (!is_array($rawEnabled)) {
            return [];
        }

        $enabled = [];
        // Keep only safe directory keys with truthy enabled flags.
        foreach ($rawEnabled as $directory => $flag) {
            // Directory keys must be safe extension slugs.
            if (
                !is_string($directory)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directory) !== 1
            ) {
                continue;
            }

            // Include directory only when its enabled flag evaluates true.
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
        $state = self::stateRead($root)->loadStateData();
        /** @var mixed $rawPermissions */
        $rawPermissions = $state['permissions'] ?? [];
        // Permission-state payload must be an associative array map.
        if (!is_array($rawPermissions)) {
            return [];
        }

        $permissions = [];
        // Keep only safe directory keys and allowed bit values.
        foreach ($rawPermissions as $directory => $rawBit) {
            // Directory keys must be safe extension slugs.
            if (
                !is_string($directory)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $directory) !== 1
            ) {
                continue;
            }

            $bit = (int) $rawBit;
            // Optional allowed-bit filter prunes unrecognized bit values.
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
        // Preserve state-file order while filtering to existing/valid directories.
        foreach (array_keys(self::enabledMap($root)) as $directory) {
            $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directory;
            // Do not activate extension directories that contain any symlink component.
            if (!Resolver::isSafeExtensionRoot($extensionRoot)) {
                continue;
            }

            // Skip enabled entries that no longer exist on disk.
            if (!is_dir($extensionRoot)) {
                continue;
            }

            // Optionally skip directories whose manifests fail validation.
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
        // Reuse cached manifest validation results within this process.
        if (array_key_exists($cacheKey, self::$manifestCache)) {
            return self::$manifestCache[$cacheKey];
        }

        $manifest = self::validateManifest()->readManifest($root, $directoryName);
        // Cache invalid/missing manifests as null to avoid repeated reads.
        if ($manifest === null) {
            self::$manifestCache[$cacheKey] = null;
            return null;
        }

        // `shortcodes.php` is optional, but when present it must return the canonical
        // shortcode item format so extension behavior stays deterministic.
        if (self::shortcodesValidationError($root, $directoryName) !== null) {
            self::$manifestCache[$cacheKey] = null;
            return null;
        }

        // `fields.php` is optional, but when present it must return canonical
        // body-block field definitions.
        if (self::fieldsValidationError($root, $directoryName) !== null) {
            self::$manifestCache[$cacheKey] = null;
            return null;
        }

        self::$manifestCache[$cacheKey] = $manifest;
        return $manifest;
    }

    /**
     * Returns normalized extension shortcode items from `private/ext/{slug}/shortcodes.php`.
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
        // Invalid providers return null so callers can distinguish from empty lists.
        if (!$validation['valid']) {
            return null;
        }

        return $validation['items'];
    }

    /**
     * Returns one provider-validation error when `shortcodes.php` is invalid.
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
     * Returns normalized extension field items from `private/ext/{slug}/fields.php`.
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
        // Invalid providers return null so callers can distinguish from empty lists.
        if (!$validation['valid']) {
            return null;
        }

        return $validation['items'];
    }

    /**
     * Returns one provider-validation error when `fields.php` is invalid.
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
        // Skip blank directory keys and already-booted extensions.
        if ($directory === '' || isset($this->bootedExtensionDirectories[$directory])) {
            return $rvn;
        }

        $this->bootedExtensionDirectories[$directory] = true;
        $provider = $this->extensionBootProviders[$directory] ?? null;
        // Extensions without callable boot providers are no-ops.
        if (!is_callable($provider)) {
            return $rvn;
        }

        // Isolate extension bootstrap failures so one extension cannot break boot.
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
        // Directory key is required to resolve one extension service map.
        if ($directory === '') {
            return [];
        }

        $this->bootExtension($rvn, $directory);

        /** @var mixed $rawExtensionServices */
        $rawExtensionServices = $rvn['extension_services'] ?? [];
        // Service container root must be an associative array.
        if (!is_array($rawExtensionServices)) {
            return [];
        }

        // Requested extension must expose an array service map.
        if (!array_key_exists($directory, $rawExtensionServices) || !is_array($rawExtensionServices[$directory])) {
            return [];
        }

        $resolutionState = $this->extensionServiceResolutionStates[$directory] ?? null;
        // Reuse currently resolving/resolved service snapshots to avoid recursion loops.
        if (is_array($resolutionState)) {
            $cachedServices = $resolutionState['services'] ?? [];
            // Return cached services for both resolving and resolved states.
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

        // Materialize lazy service values while preserving partial resolution state.
        try {
            // Resolve each top-level service entry for the requested extension.
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
        // Resolve services for every extension that has a boot provider entry.
        foreach (array_keys($this->extensionBootProviders) as $directory) {
            $services[$directory] = $this->resolveExtensionServices($rvn, $directory);
        }

        return $services;
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
        // Boot every extension that registered a bootstrap provider.
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
        // Discover boot/storage/scheduler metadata for each enabled extension directory.
        foreach ($enabledExtensionDirectories as $directory) {
            // self::readManifest() hits the static cache — no filesystem hit if
            // enabledDirectories() was called before constructing this instance.
            $manifest = self::readManifest($this->root, $directory);
            // Skip enabled directories whose manifest is missing or invalid.
            if (!is_array($manifest)) {
                continue;
            }

            $this->extensionManifests[$directory] = $manifest;

            $bootstrap = $this->bootstrapResolver->resolve($this->root, $directory, $manifest);
            // Skip extensions with invalid ext.php bootstrap contracts.
            if (!$bootstrap['valid']) {
                error_log('Raven extension bootstrap is invalid for extension "' . $directory . '": ' . (string) ($bootstrap['error'] ?? 'Unknown error.'));
                continue;
            }

            // Track scheduler-enabled extensions for cron discovery.
            if (!empty($bootstrap['scheduler'])) {
                $this->schedulerExtensions[] = $directory;
            }

            $storage = is_array($bootstrap['storage'] ?? null) ? (array) $bootstrap['storage'] : [];
            $storageMap = [
                'local'  => !empty($storage['local']) ? ($this->root . '/private/dat/ext/' . $directory) : '',
                'aux'    => [],
                'panel'  => !empty($storage['panel']) ? ($this->root . '/panel/ext/' . $directory) : '',
                'public' => !empty($storage['public']) ? ($this->root . '/public/uploads/ext/' . $directory) : '',
                // Bin storage resolves to the extension's own bin/ directory; private/bin/ contains regular launchers.
                'bin'    => !empty($storage['bin']) ? ($this->root . '/private/ext/' . $directory . '/bin') : '',
            ];

            // Register additional aux storage roots requested by the extension.
            foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
                // Skip malformed/blank aux directory declarations.
                if (!is_string($auxDirectory) || $auxDirectory === '') {
                    continue;
                }

                $storageMap['aux'][$auxDirectory] = $this->root . '/' . $auxDirectory;
            }

            // Keep each bucket in its designated location while refusing symlink escapes.
            $storagePaths = array_values($storageMap['aux']);
            foreach (['local', 'panel', 'public', 'bin'] as $storageKey) {
                $storagePath = (string) ($storageMap[$storageKey] ?? '');
                if ($storagePath !== '') {
                    $storagePaths[] = $storagePath;
                }
            }
            foreach ($storagePaths as $storagePath) {
                if (!Resolver::isSymlinkFreePath($storagePath)) {
                    error_log('Raven extension storage path contains an unsupported symlink for extension "' . $directory . '".');
                    continue 2;
                }
            }

            $this->extensionStorage[$directory] = $storageMap;

            $provider = $bootstrap['boot'] ?? null;
            // Keep only callable bootstrap providers in the runtime boot map.
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
        // Invoke lazy service closures exactly once during materialization.
        if ($value instanceof \Closure) {
            $value = $value();
        }

        // Non-array scalar/object values are already fully materialized.
        if (!is_array($value)) {
            return $value;
        }

        // Recursively materialize nested array service payloads.
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
     *   config?: \Raven\Core\Config
     * } $context
     * @return array{valid: bool, error: string, items: array<int, array{label: string, shortcode: string}>}
     */
    private static function validateShortcodesProvider(string $root, string $directoryName, array $context): array
    {
        return self::validateProvider()->validateShortcodesProvider($root, $directoryName, $context);
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
        return self::validateProvider()->validateFieldsProvider($root, $directoryName, $context);
    }

    /**
     * Returns the singleton ValidateManifest instance.
     *
     * @return ValidateManifest
     */
    private static function validateManifest(): ValidateManifest
    {
        // Lazily instantiate and cache the shared manifest validator.
        if (!self::$validateManifest instanceof ValidateManifest) {
            self::$validateManifest = new ValidateManifest();
        }

        return self::$validateManifest;
    }

    /**
     * Returns the singleton ValidateProvider instance.
     *
     * @return ValidateProvider
     */
    private static function validateProvider(): ValidateProvider
    {
        // Lazily instantiate and cache the shared provider validator.
        if (!self::$validateProvider instanceof ValidateProvider) {
            self::$validateProvider = new ValidateProvider(self::validateManifest());
        }

        return self::$validateProvider;
    }

    /**
     * Returns the cached StateRead instance for the given root.
     *
     * @param string $root Project root path.
     * @return StateRead
     */
    private static function stateRead(string $root): StateRead
    {
        $normalizedRoot = rtrim($root, '/\\');
        // Cache one StateRead instance per normalized project root.
        if (!isset(self::$stateReads[$normalizedRoot]) || !self::$stateReads[$normalizedRoot] instanceof StateRead) {
            // Auto-derives stateBasePath as private/dat/ext from extensionsBasePath.
            self::$stateReads[$normalizedRoot] = new StateRead($normalizedRoot . '/private/ext');
        }

        return self::$stateReads[$normalizedRoot];
    }
}
