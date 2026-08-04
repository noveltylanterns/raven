<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Panel/Manager.php
 * Extension catalog and manifest validation service for panel extension management.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Panel;

use Raven\Core\Config;
use Raven\Lib\Extension\Bootstrap;
use Raven\Lib\Extension\Registry;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Extension\ValidateManifest;
use Raven\Lib\Security\InputSanitizer;

/**
 * Manages the panel extension catalog: listing installed extensions, reading and validating
 * manifests, resolving permission levels, and checking name and stock-extension rules.
 */
final class Manager
{
    private string $projectRoot;
    private StateRead $stateStore;
    private Permissions $permissionCatalog;
    private Config $config;
    private InputSanitizer $input;
    private ValidateManifest $manifestValidator;
    private Bootstrap $bootstrapContractResolver;

    /**
     * Initializes the extension manager.
     *
     * @param string $projectRoot Absolute project root for extension discovery.
     * @param StateRead $stateStore Shared extension state reader for enabled/permission maps.
     * @param Permissions $permissionCatalog Shared permission catalog for level discovery and bit allocation.
     * @param Config $config Runtime config passed to extension shortcode and field contract validators.
     * @param InputSanitizer $input Shared sanitizer for human-facing extension metadata.
     * @param ValidateManifest|null $manifestValidator Optional manifest validator; defaults to a fresh instance.
     * @param Bootstrap|null $bootstrapContractResolver Optional bootstrap resolver; defaults to a fresh instance.
     */
    public function __construct(
        string $projectRoot,
        StateRead $stateStore,
        Permissions $permissionCatalog,
        Config $config,
        InputSanitizer $input,
        ?ValidateManifest $manifestValidator = null,
        ?Bootstrap $bootstrapContractResolver = null
    ) {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->stateStore = $stateStore;
        $this->permissionCatalog = $permissionCatalog;
        $this->config = $config;
        $this->input = $input;
        $this->manifestValidator = $manifestValidator ?? new ValidateManifest();
        $this->bootstrapContractResolver = $bootstrapContractResolver ?? new Bootstrap($this->manifestValidator);
    }

    /**
     * Returns the full panel extension listing, pruning stale enabled/permission state for removed extensions.
     *
     * Each entry includes display metadata, enabled state, validity, stock-extension flags,
     * and the uninstall eligibility verdict. State pruning is performed as a side-effect
     * when the on-disk extension set diverges from the persisted state map.
     *
     * @param callable(string): array<int, array<string, mixed>> $formsProvider Callback that returns insertable forms for one extension slug.
     * @return array<int, array{
     *   directory: string,
     *   type: string,
     *   panel_path: string,
     *   has_panel_routes: bool,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   homepage: string,
     *   Docs: https://lanterns.io/raven
     *   valid: bool,
     *   invalid_reason: string,
     *   enabled: bool,
     *   is_stock: bool,
     *   can_uninstall: bool,
     *   uninstall_block_reason: string
     * }>
     */
    public function listForPanel(callable $formsProvider): array
    {
        $this->stateStore->ensureDirectory();

        // Read the persisted extension-state payload once before walking disk so
        // enabled/permission maps are consistent across the full directory scan.
        $state = $this->stateStore->loadStateData();
        $enabledMap = $state['enabled'];
        $permissionMap = $state['permissions'];
        $permissionBitsMap = $state['permission_bits'];
        $entries = scandir($this->stateStore->basePath()) ?: [];
        $extensions = [];

        // Walk extension directories on disk and assemble panel catalog entries.
        foreach ($entries as $entry) {
            // Skip filesystem pseudo-entries and hidden directories.
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            // Ignore unsafe extension directory names.
            if (!$this->isSafeDirectoryName($entry)) {
                continue;
            }

            $extensionPath = $this->stateStore->basePath() . '/' . $entry;
            // Skip non-directory entries under the extension base path.
            if (!is_dir($extensionPath)) {
                continue;
            }

            $manifest = $this->readManifest($extensionPath, $formsProvider);
            $isValid = (bool) ($manifest['valid'] ?? false);
            $isEnabled = $isValid && !empty($enabledMap[$entry]);
            $hasPanelRoutes = Resolver::hasProvider($extensionPath, 'routes_panel.php');
            $isStock = $this->isStockExtensionDirectory($entry);
            $canUninstall = !$isEnabled;
            $uninstallBlockReason = '';
            // Enabled extensions must be disabled before uninstall is allowed.
            if ($isEnabled) {
                $uninstallBlockReason = 'Disable extension before uninstalling.';
            }

            $extensions[] = [
                'directory' => $entry,
                'type' => (string) ($manifest['type'] ?? 'content'),
                'panel_path' => $hasPanelRoutes ? $entry : '',
                'has_panel_routes' => $hasPanelRoutes,
                'name' => $manifest['name'] !== '' ? $manifest['name'] : $entry,
                'version' => (string) ($manifest['version'] ?? ''),
                'description' => (string) ($manifest['description'] ?? ''),
                'author' => (string) ($manifest['author'] ?? ''),
                'homepage' => (string) ($manifest['homepage'] ?? ''),
                'docs' => (string) ($manifest['docs'] ?? ''),
                'valid' => $isValid,
                'invalid_reason' => (string) ($manifest['invalid_reason'] ?? ''),
                'enabled' => $isEnabled,
                'is_stock' => $isStock,
                'can_uninstall' => $canUninstall,
                'uninstall_block_reason' => $uninstallBlockReason,
            ];
        }

        usort($extensions, static function (array $a, array $b): int {
            return strnatcasecmp((string) $a['directory'], (string) $b['directory']);
        });

        // Prune stale enabled/permission state entries for extensions no longer on disk.
        $activeKeys = array_map(
            static fn (array $extension): string => !empty($extension['valid']) ? (string) $extension['directory'] : '',
            $extensions
        );
        $activeKeys = array_values(array_filter($activeKeys, static fn (string $value): bool => $value !== ''));
        $activeKeyMap = array_flip($activeKeys);
        $cleanedEnabledMap = array_intersect_key($enabledMap, $activeKeyMap);
        $cleanedPermissionMap = array_intersect_key($permissionMap, $activeKeyMap);
        $cleanedPermissionBitsMap = array_intersect_key($permissionBitsMap, $activeKeyMap);
        // Persist state-map cleanup only when stale keys were actually removed.
        if (
            $cleanedEnabledMap !== $enabledMap
            || $cleanedPermissionMap !== $permissionMap
            || $cleanedPermissionBitsMap !== $permissionBitsMap
        ) {
            $this->stateStore->saveState($cleanedEnabledMap, $cleanedPermissionMap, $cleanedPermissionBitsMap);
        }

        return $extensions;
    }

    /**
     * Reads and validates a single extension manifest, returning a normalized metadata payload.
     *
     * Validates in layers: file presence, JSON structure, required fields, type contract,
     * bootstrap contract, shortcodes.php, and fields.php. Returns the first failure encountered
     * with a human-readable invalid_reason so the panel can display it directly.
     *
     * @param string $extensionPath Absolute path to the extension directory.
     * @param callable(string): array<int, array<string, mixed>> $formsProvider Callback that returns insertable forms for one extension slug.
     * @return array{
     *   valid: bool,
     *   invalid_reason: string,
     *   type: string,
     *   panel_path: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   author: string,
     *   homepage: string,
     *   Docs: https://lanterns.io/raven
     *   permission_levels: array<int, array{key: string, label: string}>,
     *   default_permission_level: string
     * }
     */
    public function readManifest(string $extensionPath, callable $formsProvider): array
    {
        $defaultPermissionLevels = $this->defaultPermissionLevels('Extension');
        $defaultPermissionLevel = (string) ($defaultPermissionLevels[0]['key'] ?? 'access');
        $directorySlug = trim((string) basename($extensionPath));
        // Reject unsafe directory slugs before using them in resolver calls.
        if (!$this->isSafeDirectoryName($directorySlug)) {
            $directorySlug = '';
        }

        $manifestPath = rtrim($extensionPath, '/') . '/ext.json';
        // ext.json is required for every extension entry.
        if (!is_file($manifestPath)) {
            return [
                'valid' => false,
                'invalid_reason' => 'Missing required ext.json manifest.',
                'type' => 'content',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
                'docs' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $raw = file_get_contents($manifestPath);
        // Reject missing or empty manifest payloads.
        if ($raw === false || trim($raw) === '') {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json is empty or unreadable.',
                'type' => 'content',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
                'docs' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        // Manifest must decode to a JSON object/associative array.
        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json must contain a JSON object.',
                'type' => 'content',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
                'docs' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $name = $this->input->text((string) ($decoded['name'] ?? ''), 120);
        // Name is mandatory for panel display and manifest validity.
        if ($name === '') {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json must include a non-empty "name" value.',
                'type' => 'content',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
                'docs' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $manifestSlug = strtolower(trim((string) ($decoded['slug'] ?? '')));
        // Slug must satisfy the extension directory naming contract.
        if ($manifestSlug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $manifestSlug) !== 1) {
            return [
                'valid' => false,
                'invalid_reason' => 'ext.json must include a valid "slug" value.',
                'type' => 'content',
                'panel_path' => '',
                'name' => '',
                'version' => '',
                'description' => '',
                'author' => '',
                'homepage' => '',
                'docs' => '',
                'permission_levels' => $defaultPermissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        $type = $this->manifestValidator->normalizeType((string) ($decoded['type'] ?? 'content'));
        $permissionLevels = $this->normalizePermissionLevels($decoded['panel_permissions'] ?? null, $name);
        $defaultPermissionLevel = (string) ($permissionLevels[0]['key'] ?? 'access');
        $panelPath = $directorySlug;
        $author = $this->input->text((string) ($decoded['author'] ?? ''), 120);
        $homepageRaw = trim((string) ($decoded['homepage'] ?? ''));
        $homepage = '';
        // Accept homepage only when it is a valid HTTP(S) URL.
        if ($homepageRaw !== '' && filter_var($homepageRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            // Restrict homepage scheme to web-safe protocols.
            if (in_array($scheme, ['http', 'https'], true)) {
                $homepage = $homepageRaw;
            }
        }
        $docsRaw = trim((string) ($decoded['docs'] ?? ''));
        $docs = '';
        // Accept docs link only when it is a valid HTTP(S) URL.
        if ($docsRaw !== '' && filter_var($docsRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
            // Restrict docs scheme to web-safe protocols.
            if (in_array($scheme, ['http', 'https'], true)) {
                $docs = $docsRaw;
            }
        }

        $typeContractError = $this->extensionTypeContractError($extensionPath, $type);
        // Surface extension-type contract violations as invalid manifest entries.
        if ($typeContractError !== null) {
            return [
                'valid' => false,
                'invalid_reason' => $typeContractError,
                'type' => $type,
                'panel_path' => $panelPath,
                'name' => $name,
                'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                'author' => $author,
                'homepage' => $homepage,
                'docs' => $docs,
                'permission_levels' => $permissionLevels,
                'default_permission_level' => $defaultPermissionLevel,
            ];
        }

        // Validate ext.php bootstrap contract when a safe directory slug is available.
        if ($directorySlug !== '') {
            $bootstrapContract = $this->bootstrapContractResolver->resolve($this->projectRoot, $directorySlug, [
                'type' => $type,
            ]);
            // Reject invalid ext.php provider contracts with a clear error message.
            if (!$bootstrapContract['valid']) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid ext.php: ' . (string) ($bootstrapContract['error'] ?? 'Invalid extension bootstrap contract.'),
                    'type' => $type,
                    'panel_path' => $panelPath,
                    'name' => $name,
                    'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                    'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                    'author' => $author,
                    'homepage' => $homepage,
                    'docs' => $docs,
                    'permission_levels' => $permissionLevels,
                    'default_permission_level' => $defaultPermissionLevel,
                ];
            }
        }

        // Validate shortcodes/fields providers only when a safe directory slug is available.
        if ($directorySlug !== '') {
            $shortcodesError = Registry::shortcodesValidationError(
                $this->projectRoot,
                $directorySlug,
                [
                    'extension' => $directorySlug,
                    'forms' => $formsProvider,
                    'config' => $this->config,
                ]
            );
            // Reject invalid shortcodes.php provider contracts.
            if ($shortcodesError !== null) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid shortcodes.php: ' . $shortcodesError,
                    'type' => $type,
                    'panel_path' => $panelPath,
                    'name' => $name,
                    'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                    'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                    'author' => $author,
                    'homepage' => $homepage,
                    'docs' => $docs,
                    'permission_levels' => $permissionLevels,
                    'default_permission_level' => $defaultPermissionLevel,
                ];
            }

            $fieldsError = Registry::fieldsValidationError(
                $this->projectRoot,
                $directorySlug,
                [
                    'extension' => $directorySlug,
                ]
            );
            // Reject invalid fields.php provider contracts.
            if ($fieldsError !== null) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid fields.php: ' . $fieldsError,
                    'type' => $type,
                    'panel_path' => $panelPath,
                    'name' => $name,
                    'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
                    'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
                    'author' => $author,
                    'homepage' => $homepage,
                    'docs' => $docs,
                    'permission_levels' => $permissionLevels,
                    'default_permission_level' => $defaultPermissionLevel,
                ];
            }
        }

        return [
            'valid' => true,
            'invalid_reason' => '',
            'type' => $type,
            'panel_path' => $panelPath,
            'name' => $name,
            'version' => $this->input->text((string) ($decoded['version'] ?? ''), 80),
            'description' => $this->input->text((string) ($decoded['description'] ?? ''), 1000),
            'author' => $author,
            'homepage' => $homepage,
            'docs' => $docs,
            'permission_levels' => $permissionLevels,
            'default_permission_level' => $defaultPermissionLevel,
        ];
    }

    /**
     * Returns the default single-level permission set for an extension that declares no custom levels.
     *
     * @param string $extensionName Human-readable extension name used to label the default access level.
     * @return array<int, array{key: string, label: string}> Single-entry permission level array.
     */
    public function defaultPermissionLevels(string $extensionName): array
    {
        return $this->permissionCatalog->defaultPermissionLevels($extensionName);
    }

    /**
     * Normalizes raw panel_permissions manifest data into a validated level array.
     *
     * Falls back to the default single-level set when the raw data is absent or invalid.
     *
     * @param mixed $rawLevels Raw panel_permissions value from ext.json (may be any type).
     * @param string $extensionName Human-readable extension name used for the fallback default level label.
     * @return array<int, array{key: string, label: string}> Normalized permission level array.
     */
    public function normalizePermissionLevels(mixed $rawLevels, string $extensionName): array
    {
        return $this->permissionCatalog->normalizePermissionLevels($rawLevels, $extensionName);
    }

    /**
     * Returns the full permission map with stable bit assignments for a set of extension directories.
     *
     * Delegates to the permission catalog, which handles bit allocation and persistence.
     *
     * @param array<int, string> $directoryFilter Extension directory slugs to include; empty array includes all.
     * @param callable(string): array<string, mixed>|null $manifestReader Callback that returns one extension manifest payload.
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }> Permission map keyed by extension directory slug.
     */
    public function extensionPermissionMap(array $directoryFilter, callable $manifestReader): array
    {
        return $this->permissionCatalog->extensionPermissionMap($directoryFilter, $manifestReader);
    }

    /**
     * Returns the list of stock extension directory slugs that ship with Raven.
     *
     * @return array<int, string> Stock extension directory slug list.
     */
    public function stockExtensionDirectories(): array
    {
        return ['backup', 'contact', 'cron', 'database', 'phpinfo', 'repo', 'signups'];
    }

    /**
     * Returns true when the given directory name matches a known stock extension slug.
     *
     * @param string $directoryName Extension directory name to check.
     * @return bool True when the directory is a stock extension.
     */
    public function isStockExtensionDirectory(string $directoryName): bool
    {
        $normalized = strtolower(trim($directoryName));
        return in_array($normalized, $this->stockExtensionDirectories(), true);
    }

    /**
     * Returns true when the given string is a valid extension directory name.
     *
     * Valid names start with an alphanumeric character and contain only alphanumeric
     * characters, hyphens, and underscores, up to 120 characters total.
     *
     * @param string $name Candidate directory name to validate.
     * @return bool True when the name passes the safe-name pattern.
     */
    public function isSafeDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }

    /**
     * Derives a safe extension directory name from an uploaded archive filename.
     *
     * Returns null when the filename cannot be normalized to a valid directory slug.
     *
     * @param string $archiveName Original archive filename (e.g. "my-extension.zip").
     * @return string|null Normalized slug, or null when no valid slug can be derived.
     */
    public function nameFromArchive(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 120));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        // Return null when normalized filename slug is empty or unsafe.
        if ($base === '' || !$this->isSafeDirectoryName($base)) {
            return null;
        }

        return $base;
    }

    /**
     * Returns a human-readable contract violation message when an extension directory fails its type contract,
     * or null when the directory satisfies the contract for the given type.
     *
     * @param string $extensionPath Absolute extension directory path.
     * @param string $type Normalized extension type token.
     * @return string|null Contract error message, or null on success.
     */
    private function extensionTypeContractError(string $extensionPath, string $type): ?string
    {
        return $this->manifestValidator->typeContractError($extensionPath, $type);
    }
}
