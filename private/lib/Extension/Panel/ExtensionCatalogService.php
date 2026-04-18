<?php

declare(strict_types=1);

namespace Raven\Lib\Extension\Panel;

use Raven\Core\Config;
use Raven\Lib\Extension\ExtensionRegistry;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared extension catalog and manifest validation service for panel workflows.
 */
final class ExtensionCatalogService
{
    private string $projectRoot;
    private ExtensionStateStore $stateStore;
    private ExtensionPermissionCatalogService $permissionCatalog;
    private Config $config;
    private InputSanitizer $input;
    private ManifestContractValidator $manifestValidator;
    private ExtensionBootstrapContractResolver $bootstrapContractResolver;

    public function __construct(
        string $projectRoot,
        ExtensionStateStore $stateStore,
        ExtensionPermissionCatalogService $permissionCatalog,
        Config $config,
        InputSanitizer $input,
        ?ManifestContractValidator $manifestValidator = null,
        ?ExtensionBootstrapContractResolver $bootstrapContractResolver = null
    ) {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->stateStore = $stateStore;
        $this->permissionCatalog = $permissionCatalog;
        $this->config = $config;
        $this->input = $input;
        $this->manifestValidator = $manifestValidator ?? new ManifestContractValidator();
        $this->bootstrapContractResolver = $bootstrapContractResolver ?? new ExtensionBootstrapContractResolver($this->manifestValidator);
    }

    /**
     * @param callable(string): array<int, array<string, mixed>> $formsProvider
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
     *   docs: string,
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

        $enabledMap = $this->stateStore->loadEnabledMap();
        $permissionMap = $this->stateStore->loadPermissionMap();
        $permissionBitsMap = $this->stateStore->loadPermissionBitsMap();
        $entries = scandir($this->stateStore->basePath()) ?: [];
        $extensions = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (!$this->isSafeExtensionDirectoryName($entry)) {
                continue;
            }

            $extensionPath = $this->stateStore->basePath() . '/' . $entry;
            if (!is_dir($extensionPath)) {
                continue;
            }

            $manifest = $this->readManifest($extensionPath, $formsProvider);
            $isValid = (bool) ($manifest['valid'] ?? false);
            $isEnabled = $isValid && !empty($enabledMap[$entry]);
            $hasPanelRoutes = is_file($extensionPath . '/lib/routes_panel.php');
            $isStock = $this->isStockExtensionDirectory($entry);
            $canUninstall = !$isEnabled;
            $uninstallBlockReason = '';
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

        $activeKeys = array_map(
            static fn (array $extension): string => !empty($extension['valid']) ? (string) $extension['directory'] : '',
            $extensions
        );
        $activeKeys = array_values(array_filter($activeKeys, static fn (string $value): bool => $value !== ''));
        $activeKeyMap = array_flip($activeKeys);
        $cleanedEnabledMap = array_intersect_key($enabledMap, $activeKeyMap);
        $cleanedPermissionMap = array_intersect_key($permissionMap, $activeKeyMap);
        $cleanedPermissionBitsMap = array_intersect_key($permissionBitsMap, $activeKeyMap);
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
     * @param callable(string): array<int, array<string, mixed>> $formsProvider
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
     *   docs: string,
     *   permission_levels: array<int, array{key: string, label: string}>,
     *   default_permission_level: string
     * }
     */
    public function readManifest(string $extensionPath, callable $formsProvider): array
    {
        $defaultPermissionLevels = $this->defaultPermissionLevels('Extension');
        $defaultPermissionLevel = (string) ($defaultPermissionLevels[0]['key'] ?? 'access');
        $directorySlug = trim((string) basename($extensionPath));
        if (!$this->isSafeExtensionDirectoryName($directorySlug)) {
            $directorySlug = '';
        }

        $manifestPath = rtrim($extensionPath, '/') . '/ext.json';
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
        if ($homepageRaw !== '' && filter_var($homepageRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                $homepage = $homepageRaw;
            }
        }
        $docsRaw = trim((string) ($decoded['docs'] ?? ''));
        $docs = '';
        if ($docsRaw !== '' && filter_var($docsRaw, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                $docs = $docsRaw;
            }
        }

        $typeContractError = $this->extensionTypeContractError($extensionPath, $type);
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

        if ($directorySlug !== '') {
            $bootstrapContract = $this->bootstrapContractResolver->resolve($this->projectRoot, $directorySlug, [
                'type' => $type,
            ]);
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

        if ($directorySlug !== '') {
            $shortcodesError = ExtensionRegistry::shortcodesValidationError(
                $this->projectRoot,
                $directorySlug,
                [
                    'extension' => $directorySlug,
                    'forms' => $formsProvider,
                    'config' => $this->config,
                ]
            );
            if ($shortcodesError !== null) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid lib/shortcodes.php: ' . $shortcodesError,
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

            $fieldsError = ExtensionRegistry::fieldsValidationError(
                $this->projectRoot,
                $directorySlug,
                [
                    'extension' => $directorySlug,
                ]
            );
            if ($fieldsError !== null) {
                return [
                    'valid' => false,
                    'invalid_reason' => 'Invalid lib/fields.php: ' . $fieldsError,
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
     * @return array<int, array{key: string, label: string}>
     */
    public function defaultPermissionLevels(string $extensionName): array
    {
        return $this->permissionCatalog->defaultPermissionLevels($extensionName);
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function normalizePermissionLevels(mixed $rawLevels, string $extensionName): array
    {
        return $this->permissionCatalog->normalizePermissionLevels($rawLevels, $extensionName);
    }

    /**
     * @param array<int, string> $directoryFilter
     * @param callable(string): array<string, mixed>|null $manifestReader
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }>
     */
    public function panelPermissionMapForDirectories(array $directoryFilter, callable $manifestReader): array
    {
        return $this->permissionCatalog->panelPermissionMapForDirectories($directoryFilter, $manifestReader);
    }

    /**
     * @return array<int, string>
     */
    public function stockExtensionDirectories(): array
    {
        return ['contact', 'cron', 'database', 'phpinfo', 'repo', 'signups', 'smallweb'];
    }

    public function isStockExtensionDirectory(string $directoryName): bool
    {
        $normalized = strtolower(trim($directoryName));
        return in_array($normalized, $this->stockExtensionDirectories(), true);
    }

    public function isSafeExtensionDirectoryName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $name);
    }

    public function extensionNameFromArchiveFilename(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 120));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        if ($base === '' || !$this->isSafeExtensionDirectoryName($base)) {
            return null;
        }

        return $base;
    }

    private function extensionTypeContractError(string $extensionPath, string $type): ?string
    {
        return $this->manifestValidator->typeContractError($extensionPath, $type);
    }
}
