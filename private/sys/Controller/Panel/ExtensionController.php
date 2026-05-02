<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ExtensionController.php
 * Split panel extension-manager controller that owns `/extensions*` routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Runtime\DatabaseFactory;
use Raven\Lib\Archive\Folder as ArchiveDelete;
use Raven\Lib\Archive\Install as ArchiveInstall;
use Raven\Lib\Archive\Package as ArchivePackage;
use Raven\Lib\Extension\Bootstrap;
use Raven\Lib\Extension\StorageCleaner;
use Raven\Lib\Extension\StorageProvisioner;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Extension\Panel\ExtensionCatalogService;
use Raven\Lib\Extension\Scaffold;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Upload;
use Raven\Lib\Transport\Redirect;

/**
 * Handles panel extension-manager routes.
 */
final class ExtensionController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private string $root;
    /** @var Closure(string): array<string, mixed> */
    private Closure $extensionServicesFor;
    private StateRead $extensionStateStore;
    private ExtensionCatalogService $extensionCatalogService;
    private ?ArchivePackage $archivePackages = null;
    private ?ArchiveInstall $packageInstallWorkflowService = null;
    private ?ArchiveDelete $directoryTreeService = null;
    private ?Scaffold $extensionScaffoldService = null;
    private ?StorageProvisioner $extensionStorageProvisioner = null;
    private ?Bootstrap $extensionBootstrapContractResolver = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for filesystem-backed admin workflows.
     * @param StateRead $extensionStateStore Shared extension state store for panel extension reads/writes.
     * @param ExtensionCatalogService $extensionCatalogService Shared extension catalog for manifest and stock-extension reads.
     * @param callable(string): array<string, mixed> $extensionServicesFor Lazy per-extension services resolver.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        StateRead $extensionStateStore,
        ExtensionCatalogService $extensionCatalogService,
        callable $extensionServicesFor
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->extensionStateStore = $extensionStateStore;
        $this->extensionCatalogService = $extensionCatalogService;
        $this->extensionServicesFor = Closure::fromCallable($extensionServicesFor);
    }

    /**
     * Renders the extension manager.
     *
     * @return void
     */
    public function extensions(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        try {
            $extensions = $this->extensionCatalogService->listForPanel(
                fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
            );
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            $extensions = [];
        }

        $archivePackages = $this->archivePackages();

        $this->context->renderPanel('panel/extensions', [
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'extensions',
            'extensions' => $extensions,
            'packageArchiveAcceptAttribute' => $archivePackages->packageAccept(),
            'packageArchiveFormats' => $archivePackages->packageFormatLabels(),
            'exportArchiveFormats' => $archivePackages->exportFormatOptions(),
        ]);
    }

    /**
     * Toggles one extension enabled or disabled.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function extensionsToggle(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->extensionCatalogService->isSafeExtensionDirectoryName((string) $extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->extensionCatalogService->readManifest(
            $extensionPath,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
        if (!($manifest['valid'] ?? false)) {
            $enabledMap = $this->extensionStateStore->loadEnabledMap();
            if (isset($enabledMap[$extensionName])) {
                unset($enabledMap[$extensionName]);
                $this->extensionStateStore->saveEnabledMap($enabledMap);
            }

            $reason = (string) ($manifest['invalid_reason'] ?? 'Invalid extension metadata.');
            $this->context->flash('error', 'Extension is invalid: ' . $reason);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $enabledRaw = strtolower((string) $this->input->text($post['enabled'] ?? null, 10));
        if (!in_array($enabledRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            $this->context->flash('error', 'Invalid extension toggle value.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $enable = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

        try {
            if ($enable) {
                $this->provisionEnabledExtensionStorage((string) $extensionName, $manifest);
            }

            $enabledMap = $this->extensionStateStore->loadEnabledMap();
            if ($enable) {
                $enabledMap[(string) $extensionName] = true;
            } else {
                unset($enabledMap[(string) $extensionName]);
            }

            $this->extensionStateStore->saveEnabledMap($enabledMap);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $this->context->flash('success', 'Extension "' . $extensionName . '" ' . ($enable ? 'enabled' : 'disabled') . '.');
        Redirect::redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Redirects operators to the group-permission UI for extension permission levels.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function extensionsPermission(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $this->context->flash('error', 'Extension permission levels are managed in Groups > Permissions.');
        Redirect::redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Uninstalls one extension package or purges one stock extension's data.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function extensionsUninstall(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'uninstall')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->extensionCatalogService->isSafeExtensionDirectoryName((string) $extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->extensionCatalogService->readManifest(
            $extensionPath,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
        $isStockExtension = $this->extensionCatalogService->isStockExtensionDirectory((string) $extensionName);

        $state = $this->extensionStateStore->loadStateData();
        $enabledMap = $state['enabled'];
        $permissionMap = $state['permissions'];
        $permissionBitsMap = $state['permission_bits'];
        if (!empty($enabledMap[$extensionName])) {
            $this->context->flash('error', 'Disable the extension before uninstalling it.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $this->deleteExtensionStorage((string) $extensionName, $manifest);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Failed to uninstall extension storage: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        if ($isStockExtension) {
            $this->context->flash('success', 'Stock extension "' . $extensionName . '" data purged. Bundled extension files were kept.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $this->directoryTreeService()->removeTree($extensionPath);
        if (is_dir($extensionPath)) {
            $this->context->flash('error', 'Failed to uninstall extension directory from disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        if (
            isset($enabledMap[$extensionName])
            || isset($permissionMap[$extensionName])
            || isset($permissionBitsMap[$extensionName])
        ) {
            unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
            try {
                $this->extensionStateStore->saveState($enabledMap, $permissionMap, $permissionBitsMap);
            } catch (\RuntimeException $exception) {
                $this->context->flash('error', 'Extension uninstalled, but state cleanup failed: ' . $exception->getMessage());
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }
        }

        $this->context->flash('success', 'Extension "' . $extensionName . '" uninstalled.');
        Redirect::redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Uploads one extension archive package.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload.
     * @return void
     */
    public function extensionsUpload(array $post, array $files): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $upload = $this->packageInstallWorkflowService()->validateUpload(
            $files['extension_archive'] ?? null,
            'Extension archive',
            'Extensions'
        );
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->context->flash('error', (string) ($upload['error'] ?? 'Extension upload failed.'));
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'extension-package.zip');
        $derivedExtensionSlug = $this->packageInstallWorkflowService()->extensionSlug($tmpPath);

        $nameResult = $this->packageInstallWorkflowService()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedExtensionSlug,
            fn (string $name): bool => $this->extensionCatalogService->isSafeExtensionDirectoryName($name),
            fn (string $name): bool => $this->extensionCatalogService->isStockExtensionDirectory($name),
            fn (string $name): ?string => $this->nextAvailableExtensionDirectoryName($name),
            fn (string $name): bool => file_exists($this->extensionStateStore->basePath() . '/' . $name),
            'Extension',
            'Extension directory must use lowercase letters, numbers, underscores, or dashes.'
        );
        if (!(bool) ($nameResult['ok'] ?? false)) {
            $nameError = (string) ($nameResult['error'] ?? 'Failed to resolve extension directory name.');
            if (trim((string) ($post['upload_slug'] ?? '')) === '' && $derivedExtensionSlug === null) {
                $nameError = 'Extension upload failed: ext.json must include a valid "slug" value.';
            }

            $this->context->flash('error', $nameError);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }
        $extensionName = (string) ($nameResult['name'] ?? '');

        try {
            $this->extensionStateStore->ensureDirectory();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $targetDirectory = $this->extensionStateStore->basePath() . '/' . $extensionName;
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->context->flash('error', 'Failed to create extension directory.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extractError = $this->packageInstallWorkflowService()->extractTo(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTreeService()->removeTree($directory);
            },
            'extension'
        );
        if (is_string($extractError)) {
            $this->context->flash('error', $extractError);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $flattenError = $this->packageInstallWorkflowService()->flattenRoot($targetDirectory);
        if (is_string($flattenError)) {
            $this->directoryTreeService()->removeTree($targetDirectory);
            $this->context->flash('error', $flattenError);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->extensionCatalogService->readManifest(
            $targetDirectory,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
        if (!($manifest['valid'] ?? false)) {
            $this->directoryTreeService()->removeTree($targetDirectory);
            $reason = (string) ($manifest['invalid_reason'] ?? 'Missing required extension metadata.');
            $this->context->flash('error', 'Extension upload failed: ' . $reason);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $state = $this->extensionStateStore->loadStateData();
            $enabledMap = $state['enabled'];
            $permissionMap = $state['permissions'];
            $permissionBitsMap = $state['permission_bits'];
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->extensionStateStore->saveState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeTree($targetDirectory);
            $this->context->flash('error', 'Extension upload failed: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $message = 'Extension uploaded to private/ext/' . $extensionName . '/. It is disabled by default.';
        if ((bool) ($nameResult['renamed'] ?? false)) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }

        $this->context->flash('success', $message);
        Redirect::redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Exports one installed extension directory as one downloadable archive.
     *
     * @param array<string, mixed> $query Query-string payload.
     * @return void
     */
    public function extensionsExport(array $query): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        $extensionName = strtolower(trim((string) $this->input->text($query['extension'] ?? null, 120)));
        if (!$this->extensionCatalogService->isSafeExtensionDirectoryName($extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $format = strtolower(trim((string) $this->input->text($query['format'] ?? 'zip', 20)));

        try {
            $archive = $this->archivePackages()->exportDir($extensionPath, $extensionName, $format);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Extension export failed: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $downloadFilename = $this->archivePackages()->downloadName('extension-' . $extensionName, (string) ($archive['format'] ?? 'zip'));
        $this->archivePackages()->streamDownload(
            (string) ($archive['path'] ?? ''),
            $downloadFilename,
            (string) ($archive['mime_type'] ?? 'application/octet-stream')
        );
    }

    /**
     * Creates one new extension scaffold.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function extensionsCreate(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim((string) $this->input->text($post['extension'] ?? null, 120)));
        if ($extensionName === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $extensionName) !== 1) {
            $this->context->flash('error', 'Extension directory must use lowercase letters, numbers, underscores, or dashes.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        if ($this->extensionCatalogService->isStockExtensionDirectory($extensionName)) {
            $this->context->flash('error', 'That extension directory name is reserved by a stock extension.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $displayName = $this->input->text($post['name'] ?? null, 120);
        if ($displayName === '') {
            $this->context->flash('error', 'Extension name is required.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $type = strtolower(trim((string) $this->input->text($post['type'] ?? null, 20)));
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            $type = 'content';
        }

        $version = $this->input->text($post['version'] ?? null, 80);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $author = $this->input->text($post['author'] ?? null, 120);

        $homepageRaw = trim((string) $this->input->text($post['homepage'] ?? null, 400));
        $homepage = '';
        if ($homepageRaw !== '') {
            if (filter_var($homepageRaw, FILTER_VALIDATE_URL) === false) {
                $this->context->flash('error', 'Author URL must be a valid absolute URL.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->context->flash('error', 'Author URL must use http or https.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $homepage = $homepageRaw;
        }

        $docsRaw = trim((string) $this->input->text($post['docs'] ?? null, 400));
        $docs = '';
        if ($docsRaw !== '') {
            if (filter_var($docsRaw, FILTER_VALIDATE_URL) === false) {
                $this->context->flash('error', 'Documentation URL must be a valid absolute URL.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->context->flash('error', 'Documentation URL must use http or https.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $docs = $docsRaw;
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';

        try {
            $this->extensionStateStore->ensureDirectory();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        if (file_exists($extensionPath)) {
            $this->context->flash('error', 'An extension directory with this name already exists.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $this->extensionScaffoldService()->createSkeleton(
                $extensionPath,
                [
                    'directory' => $extensionName,
                    'name' => (string) $displayName,
                    'version' => (string) $version,
                    'description' => (string) $description,
                    'type' => $type,
                    'author' => (string) $author,
                    'homepage' => $homepage,
                    'docs' => $docs,
                ],
                $generateAgentsFile,
                $generateComposerFile
            );
        } catch (\Throwable $exception) {
            $this->directoryTreeService()->removeTree($extensionPath);
            $this->context->flash('error', 'Failed to create extension scaffold: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $state = $this->extensionStateStore->loadStateData();
            $enabledMap = $state['enabled'];
            $permissionMap = $state['permissions'];
            $permissionBitsMap = $state['permission_bits'];
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->extensionStateStore->saveState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeTree($extensionPath);
            $this->context->flash('error', 'Extension scaffold created, but state finalization failed: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $createdFiles = ['ext.json', 'ext.php', 'schema.php'];
        if (in_array($type, ['content', 'module'], true)) {
            $createdFiles[] = 'shortcodes.php';
            $createdFiles[] = 'fields.php';
        }
        if ($type !== 'framework') {
            $createdFiles = array_merge($createdFiles, ['routes_panel.php', 'tpl/panel_index.php']);
        }
        if ($type === 'module') {
            $createdFiles[] = 'routes_public.php';
            $createdFiles[] = 'tpl/public_index.php';
        }
        if ($generateAgentsFile) {
            $createdFiles[] = 'agents';
            $createdFiles[] = 'AGENTS.md -> agents';
            $createdFiles[] = 'CLAUDE.md -> agents';
        }
        if ($generateComposerFile) {
            $createdFiles[] = 'composer.json';
        }

        $createdList = $createdFiles[0] ?? 'ext.json';
        if (count($createdFiles) === 2) {
            $createdList = $createdFiles[0] . ' and ' . $createdFiles[1];
        } elseif (count($createdFiles) > 2) {
            $createdList = implode(', ', array_slice($createdFiles, 0, -1))
                . ', and '
                . $createdFiles[count($createdFiles) - 1];
        }

        $this->context->flash(
            'success',
            'Extension scaffold created at private/ext/' . $extensionName
            . '/ with ' . $createdList
            . '.'
        );
        Redirect::redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Resolves the next available extension directory name by appending copy suffixes.
     */
    private function nextAvailableExtensionDirectoryName(string $baseName): ?string
    {
        $normalizedBase = strtolower(trim($baseName));
        if (!$this->extensionCatalogService->isSafeExtensionDirectoryName($normalizedBase)) {
            return null;
        }

        $extensionsRoot = $this->extensionStateStore->basePath();
        $candidate = $normalizedBase;
        if (!file_exists($extensionsRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 500; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 120 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            if ($trimmedBase === '') {
                $trimmedBase = 'extension';
            }

            $candidate = $trimmedBase . $suffix;
            if (!$this->extensionCatalogService->isSafeExtensionDirectoryName($candidate)) {
                continue;
            }

            if (!file_exists($extensionsRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns enabled extension forms exposed to extension manifests.
     *
     * @param string $extensionKey Extension directory key.
     * @return array<int, array{name: string, slug: string}> Enabled extension form rows.
     */
    private function listEnabledExtensionForms(string $extensionKey): array
    {
        $normalized = strtolower(trim($extensionKey));
        $extensionServices = ($this->extensionServicesFor)($normalized);
        if (is_array($extensionServices)) {
            $formsRepository = $extensionServices['forms'] ?? null;
            if (is_object($formsRepository) && method_exists($formsRepository, 'listAll')) {
                /** @var mixed $rows */
                $rows = $formsRepository->listAll();
                if (is_array($rows)) {
                    $items = [];
                    foreach ($rows as $row) {
                        if (!is_array($row) || empty($row['enabled'])) {
                            continue;
                        }

                        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
                        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                            continue;
                        }

                        $name = trim((string) ($row['name'] ?? ''));
                        if ($name === '') {
                            $name = $slug;
                        }

                        $items[] = [
                            'name' => $name,
                            'slug' => $slug,
                        ];
                    }

                    return $items;
                }
            }
        }

        return [];
    }

    /**
     * Returns the extension storage provisioner on first use.
     */
    private function extensionStorageProvisioner(): StorageProvisioner
    {
        if (!$this->extensionStorageProvisioner instanceof StorageProvisioner) {
            $this->extensionStorageProvisioner = new StorageProvisioner($this->root);
        }

        return $this->extensionStorageProvisioner;
    }

    /**
     * Returns the extension bootstrap-contract resolver on first use.
     */
    private function extensionBootstrapContractResolver(): Bootstrap
    {
        if (!$this->extensionBootstrapContractResolver instanceof Bootstrap) {
            $this->extensionBootstrapContractResolver = new Bootstrap();
        }

        return $this->extensionBootstrapContractResolver;
    }

    /**
     * Provisions filesystem/database storage for an enabled extension.
     *
     * @param string $extensionName Extension directory name.
     * @param array<string, mixed> $manifest Resolved extension manifest.
     * @return void
     */
    private function provisionEnabledExtensionStorage(string $extensionName, array $manifest): void
    {
        $contract = $this->extensionBootstrapContractResolver()->resolve($this->root, $extensionName, $manifest);
        if (!$contract['valid']) {
            throw new \RuntimeException((string) ($contract['error'] ?? 'Invalid extension bootstrap contract.'));
        }

        $this->extensionStorageProvisioner()->provision(
            $extensionName,
            (array) ($contract['storage'] ?? [])
        );
    }

    /**
     * Deletes filesystem/database storage for an uninstalled extension.
     *
     * @param string $extensionName Extension directory name.
     * @param array<string, mixed> $manifest Resolved extension manifest.
     * @return void
     */
    private function deleteExtensionStorage(string $extensionName, array $manifest): void
    {
        $contract = $this->extensionBootstrapContractResolver()->resolve($this->root, $extensionName, $manifest);
        if (!$contract['valid']) {
            throw new \RuntimeException((string) ($contract['error'] ?? 'Invalid extension bootstrap contract.'));
        }

        $databaseConfig = (array) $this->config->get('database', []);
        $connectionFactory = new DatabaseFactory($databaseConfig);
        $cleaner = new StorageCleaner(
            $this->root,
            $connectionFactory->createAppConnection(),
            $connectionFactory->getDriver(),
            $connectionFactory->getPrefix()
        );

        $cleaner->deleteStorageByContract($extensionName, (array) ($contract['storage'] ?? []));
    }

    /**
     * Returns the archive-package service on first use.
     */
    private function archivePackages(): ArchivePackage
    {
        if (!$this->archivePackages instanceof ArchivePackage) {
            $this->archivePackages = new ArchivePackage($this->root);
        }

        return $this->archivePackages;
    }

    /**
     * Returns the extension-scaffold service on first use.
     */
    private function extensionScaffoldService(): Scaffold
    {
        if (!$this->extensionScaffoldService instanceof Scaffold) {
            $this->extensionScaffoldService = new Scaffold();
        }

        return $this->extensionScaffoldService;
    }

    /**
     * Returns the package-install workflow service on first use.
     */
    private function packageInstallWorkflowService(): ArchiveInstall
    {
        if (!$this->packageInstallWorkflowService instanceof ArchiveInstall) {
            $this->packageInstallWorkflowService = new ArchiveInstall(
                $this->input,
                new Upload(),
                $this->archivePackages()
            );
        }

        return $this->packageInstallWorkflowService;
    }

    /**
     * Returns the directory-tree helper on first use.
     */
    private function directoryTreeService(): ArchiveDelete
    {
        if (!$this->directoryTreeService instanceof ArchiveDelete) {
            $this->directoryTreeService = new ArchiveDelete();
        }

        return $this->directoryTreeService;
    }
}
