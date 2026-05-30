<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ExtensionController.php
 * Split panel extension-manager controller that owns `/extensions*` routes.
 * Docs: https://lanterns.io/raven
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
use Raven\Lib\Extension\Panel\Manager as ExtensionManager;
use Raven\Lib\Extension\Scaffold;
use Raven\Lib\Extension\StateRead;
use Raven\Lib\Extension\StorageCleaner;
use Raven\Lib\Extension\StorageProvisioner;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;

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
    private Closure $extensionServices;
    private StateRead $extensionStateStore;
    private ExtensionManager $extensionManager;
    private ?ArchivePackage $archivePackages = null;
    private ?ArchiveInstall $packageInstaller = null;
    private ?ArchiveDelete $directoryTree = null;
    private ?Scaffold $extensionScaffold = null;
    private ?StorageProvisioner $extensionStorageProvisioner = null;
    private ?Bootstrap $extensionBootstrap = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for filesystem-backed admin workflows.
     * @param StateRead $extensionStateStore Shared extension state store for panel extension reads/writes.
     * @param ExtensionManager $extensionManager Shared extension catalog for manifest and stock-extension reads.
     * @param callable(string): array<string, mixed> $extensionServices Lazy per-extension services resolver.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        StateRead $extensionStateStore,
        ExtensionManager $extensionManagerService,
        callable $extensionServices
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->extensionStateStore = $extensionStateStore;
        $this->extensionManagerService = $extensionManagerService;
        $this->extensionServices = Closure::fromCallable($extensionServices);
    }

    /**
     * Renders the extension manager.
     *
     * @return void
     */
    public function extensions(): void
    {
        $this->context->requirePanelLogin();
        // Viewing extensions is permission-gated to avoid exposing installed package details.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        // Manifest parsing can fail when an extension on disk is malformed.
        try {
            $extensions = $this->extensionManagerService->listForPanel(
                fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
            );
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            $extensions = [];
        }

        $archivePackages = $this->archivePackages();

        $this->context->renderPanel('panel/extensions', [
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'extensions',
            'extensions' => $extensions,
            'packageArchiveAcceptAttribute' => $archivePackages->accept(),
            'packageArchiveFormats' => $archivePackages->formatLabels(),
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
        // Toggling install state is an edit-level action.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        // CSRF validation prevents cross-site state flips.
        // CSRF validation protects package upload endpoints.
        // CSRF validation protects extension package uploads.
        // CSRF validation protects archive upload submissions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        // Extension IDs are limited to safe directory slugs.
        if (!$this->extensionManagerService->isSafeDirectoryName((string) $extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        // Refuse toggles when extension files are missing from disk.
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->extensionManagerService->readManifest(
            $extensionPath,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
        // Invalid manifests cannot participate in runtime bootstrap, so force-disable.
        if (!($manifest['valid'] ?? false)) {
            $enabledMap = $this->extensionStateStore->loadEnabledMap();
            // Clean stale enabled flags so boot does not keep trying to load invalid packages.
            if (isset($enabledMap[$extensionName])) {
                unset($enabledMap[$extensionName]);
                $this->extensionStateStore->saveEnabledMap($enabledMap);
            }

            $reason = (string) ($manifest['invalid_reason'] ?? 'Invalid extension metadata.');
            $this->context->flash('error', 'Extension is invalid: ' . $reason);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $enabledRaw = strtolower((string) $this->input->text($post['enabled'] ?? null, 10));
        // Only explicit boolean-like values are accepted from the toggle form.
        if (!in_array($enabledRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            $this->context->flash('error', 'Invalid extension toggle value.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $enable = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

        // Provisioning and state persistence may fail due to filesystem or schema issues.
        try {
            // Enabling runs storage/bootstrap prep before persisting state.
            if ($enable) {
                $this->provisionEnabledExtensionStorage((string) $extensionName, $manifest);
            }

            $enabledMap = $this->extensionStateStore->loadEnabledMap();
            // Persist enabled state as a sparse map keyed by extension slug.
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
        // Permission UI redirect is an edit-level operation.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'edit')) {
            return;
        }

        // CSRF validation protects redirect intents the same as mutating actions.
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
        // Uninstall and purge actions are scoped to dedicated permission.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'uninstall')) {
            return;
        }

        // CSRF validation protects destructive extension actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        // Extension IDs are constrained to safe directory names.
        if (!$this->extensionManagerService->isSafeDirectoryName((string) $extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        // Require on-disk package presence before purge/uninstall attempts.
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->extensionManagerService->readManifest(
            $extensionPath,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
        $isStockExtension = $this->extensionManagerService->isStockExtensionDirectory((string) $extensionName);

        $state = $this->extensionStateStore->loadStateData();
        $enabledMap = $state['enabled'];
        $permissionMap = $state['permissions'];
        $permissionBitsMap = $state['permission_bits'];
        // Prevent uninstalling enabled extensions to avoid live runtime breakage.
        if (!empty($enabledMap[$extensionName])) {
            $this->context->flash('error', 'Disable the extension before uninstalling it.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Extension cleanup can fail if storage/database teardown hits an error.
        try {
            $this->deleteExtensionStorage((string) $extensionName, $manifest);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Failed to uninstall extension storage: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Stock extensions keep shipped files and only purge runtime data.
        if ($isStockExtension) {
            $this->context->flash('success', 'Stock extension "' . $extensionName . '" data purged. Bundled extension files were kept.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $this->directoryTree()->removeTree($extensionPath);
        // Verify directory removal because recursive delete can partially fail.
        if (is_dir($extensionPath)) {
            $this->context->flash('error', 'Failed to uninstall extension directory from disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Clear all persisted state maps once package files are removed.
        if (
            isset($enabledMap[$extensionName])
            || isset($permissionMap[$extensionName])
            || isset($permissionBitsMap[$extensionName])
        ) {
            unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
            // Persist state cleanup separately so uninstall still succeeds if there is nothing to prune.
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
        // Installing new extensions is a create-level action.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        // CSRF validation protects extension upload mutations.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $upload = $this->packageInstaller()->validateUpload(
            $files['extension_archive'] ?? null,
            'Extension archive',
            'Extensions'
        );
        // Upload validator enforces archive type/size and normalized temp path.
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->context->flash('error', (string) ($upload['error'] ?? 'Extension upload failed.'));
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'extension-package.zip');
        $derivedExtensionSlug = $this->packageInstaller()->extensionSlug($tmpPath);

        $nameResult = $this->packageInstaller()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedExtensionSlug,
            fn (string $name): bool => $this->extensionManagerService->isSafeDirectoryName($name),
            fn (string $name): bool => $this->extensionManagerService->isStockExtensionDirectory($name),
            fn (string $name): ?string => $this->nextExtensionDirectory($name),
            fn (string $name): bool => file_exists($this->extensionStateStore->basePath() . '/' . $name),
            'Extension',
            'Extension directory must use lowercase letters, numbers, underscores, or dashes.'
        );
        // Resolve final install slug and block conflicts/invalid names.
        if (!(bool) ($nameResult['ok'] ?? false)) {
            $nameError = (string) ($nameResult['error'] ?? 'Failed to resolve extension directory name.');
            // If no slug can be derived, surface a direct manifest guidance message.
            if (trim((string) ($post['upload_slug'] ?? '')) === '' && $derivedExtensionSlug === null) {
                $nameError = 'Extension upload failed: ext.json must include a valid "slug" value.';
            }

            $this->context->flash('error', $nameError);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }
        $extensionName = (string) ($nameResult['name'] ?? '');

        // Ensure private/ext exists before filesystem extraction.
        try {
            $this->extensionStateStore->ensureDirectory();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $targetDirectory = $this->extensionStateStore->basePath() . '/' . $extensionName;
        // Create target dir atomically; `is_dir` check handles races with parallel installs.
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->context->flash('error', 'Failed to create extension directory.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extractError = $this->packageInstaller()->extractTo(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTree()->removeTree($directory);
            },
            'extension'
        );
        // Extraction errors include archive-format and filesystem failures.
        if (is_string($extractError)) {
            $this->context->flash('error', $extractError);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $flattenError = $this->packageInstaller()->flattenRoot($targetDirectory);
        // Flatten nested package roots so extension files live at directory root.
        if (is_string($flattenError)) {
            $this->directoryTree()->removeTree($targetDirectory);
            $this->context->flash('error', $flattenError);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->extensionManagerService->readManifest(
            $targetDirectory,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
        // Refuse install when manifest fails schema/contract checks.
        if (!($manifest['valid'] ?? false)) {
            $this->directoryTree()->removeTree($targetDirectory);
            $reason = (string) ($manifest['invalid_reason'] ?? 'Missing required extension metadata.');
            $this->context->flash('error', 'Extension upload failed: ' . $reason);
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Remove stale state rows if this slug previously existed.
        try {
            $state = $this->extensionStateStore->loadStateData();
            $enabledMap = $state['enabled'];
            $permissionMap = $state['permissions'];
            $permissionBitsMap = $state['permission_bits'];
            // Clear any legacy state keys so newly uploaded package starts clean.
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->extensionStateStore->saveState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTree()->removeTree($targetDirectory);
            $this->context->flash('error', 'Extension upload failed: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $message = 'Extension uploaded to private/ext/' . $extensionName . '/. It is disabled by default.';
        // Tell operators when we auto-renamed the install directory to avoid collision.
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
        // Exporting package archives is view-scoped in panel permissions.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'view')) {
            return;
        }

        $extensionName = strtolower(trim((string) $this->input->text($query['extension'] ?? null, 120)));
        // Extension slug must be a safe directory token.
        if (!$this->extensionManagerService->isSafeDirectoryName($extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        // Export only installed extensions that still exist on disk.
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $format = strtolower(trim((string) $this->input->text($query['format'] ?? 'zip', 20)));

        // Archive generation can fail on unsupported formats or filesystem errors.
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
        // Scaffold creation is a create-level panel action.
        if (!$this->context->requireRoutePermissionOrForbidden('extensions', 'create')) {
            return;
        }

        // CSRF validation protects scaffold generation.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim((string) $this->input->text($post['extension'] ?? null, 120)));
        // Enforce canonical extension slug constraints for filesystem safety.
        if ($extensionName === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $extensionName) !== 1) {
            $this->context->flash('error', 'Extension directory must use lowercase letters, numbers, underscores, or dashes.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Block reserved names that map to bundled stock extensions.
        if ($this->extensionManagerService->isStockExtensionDirectory($extensionName)) {
            $this->context->flash('error', 'That extension directory name is reserved by a stock extension.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $displayName = $this->input->text($post['name'] ?? null, 120);
        // Human-readable extension name is required in generated manifest.
        if ($displayName === '') {
            $this->context->flash('error', 'Extension name is required.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $type = strtolower(trim((string) $this->input->text($post['type'] ?? null, 20)));
        // Unknown type values are coerced to content as safe default.
        if (!in_array($type, ['helper', 'content', 'framework', 'module', 'system'], true)) {
            $type = 'content';
        }

        $version = $this->input->text($post['version'] ?? null, 80);
        $description = $this->input->text($post['description'] ?? null, 1000);
        $author = $this->input->text($post['author'] ?? null, 120);

        $homepageRaw = trim((string) $this->input->text($post['homepage'] ?? null, 400));
        $homepage = '';
        // Homepage field is optional but validated strictly when supplied.
        if ($homepageRaw !== '') {
            // Require absolute URL to avoid broken relative links in manifest consumers.
            if (filter_var($homepageRaw, FILTER_VALIDATE_URL) === false) {
                $this->context->flash('error', 'Author URL must be a valid absolute URL.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            // Only http/https schemes are supported in extension metadata.
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->context->flash('error', 'Author URL must use http or https.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $homepage = $homepageRaw;
        }

        $docsRaw = trim((string) $this->input->text($post['docs'] ?? null, 400));
        $docs = '';
        // Docs URL remains optional but must be valid when provided.
        if ($docsRaw !== '') {
            // Require absolute URLs to keep manifest metadata unambiguous.
            if (filter_var($docsRaw, FILTER_VALIDATE_URL) === false) {
                $this->context->flash('error', 'Documentation URL must be a valid absolute URL.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
            // Allow only web-safe schemes used by panel links.
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->context->flash('error', 'Documentation URL must use http or https.');
                Redirect::redirect($this->context->panelUrl('/extensions'));
            }

            $docs = $docsRaw;
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';

        // Ensure extension root directory exists before scaffold generation.
        try {
            $this->extensionStateStore->ensureDirectory();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionStateStore->basePath() . '/' . $extensionName;
        // Prevent overwriting existing extension directories.
        if (file_exists($extensionPath)) {
            $this->context->flash('error', 'An extension directory with this name already exists.');
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Scaffold creation can fail due to template IO or serialization errors.
        try {
            $this->extensionScaffold()->createSkeleton(
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
            $this->directoryTree()->removeTree($extensionPath);
            $this->context->flash('error', 'Failed to create extension scaffold: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        // Normalize stale permission/enabled rows for reused extension slugs.
        try {
            $state = $this->extensionStateStore->loadStateData();
            $enabledMap = $state['enabled'];
            $permissionMap = $state['permissions'];
            $permissionBitsMap = $state['permission_bits'];
            // Remove any prior state entries so new scaffold starts disabled and clean.
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->extensionStateStore->saveState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTree()->removeTree($extensionPath);
            $this->context->flash('error', 'Extension scaffold created, but state finalization failed: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/extensions'));
        }

        $createdFiles = ['ext.json', 'ext.php', 'schema.php'];
        // Content/module extensions include template field and shortcode scaffolds.
        if (in_array($type, ['content', 'module'], true)) {
            $createdFiles[] = 'shortcodes.php';
            $createdFiles[] = 'fields.php';
        }
        // Non-framework types ship panel route/view stubs.
        if ($type !== 'framework') {
            $createdFiles = array_merge($createdFiles, ['routes_panel.php', 'tpl/panel_index.php']);
        }
        // Modules also expose public routes and a public view scaffold.
        if ($type === 'module') {
            $createdFiles[] = 'routes_public.php';
            $createdFiles[] = 'tpl/public_index.php';
        }
        // Optional agent docs/symlinks are included when requested.
        if ($generateAgentsFile) {
            $createdFiles[] = 'agents';
            $createdFiles[] = 'AGENTS.md -> agents';
            $createdFiles[] = 'CLAUDE.md -> agents';
        }
        // Optional Composer metadata file is included when requested.
        if ($generateComposerFile) {
            $createdFiles[] = 'composer.json';
        }

        $createdList = $createdFiles[0] ?? 'ext.json';
        // Format two-file lists using natural-language conjunction.
        if (count($createdFiles) === 2) {
            $createdList = $createdFiles[0] . ' and ' . $createdFiles[1];
        } elseif (count($createdFiles) > 2) {
            // Format longer file lists with a serial comma for readability.
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
    private function nextExtensionDirectory(string $baseName): ?string
    {
        $normalizedBase = strtolower(trim($baseName));
        // Bail out early when base slug is not directory-safe.
        if (!$this->extensionManagerService->isSafeDirectoryName($normalizedBase)) {
            return null;
        }

        $extensionsRoot = $this->extensionStateStore->basePath();
        $candidate = $normalizedBase;
        // Reuse original slug when no collision exists.
        if (!file_exists($extensionsRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= 500; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 120 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            // Ensure suffixing still leaves a non-empty slug stem.
            if ($trimmedBase === '') {
                $trimmedBase = 'extension';
            }

            $candidate = $trimmedBase . $suffix;
            // Re-validate each generated candidate before probing filesystem.
            if (!$this->extensionManagerService->isSafeDirectoryName($candidate)) {
                continue;
            }

            // Return first collision-free candidate.
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
        $extensionServices = ($this->extensionServices)($normalized);
        // Extension service registries are optional for package types without forms.
        if (is_array($extensionServices)) {
            $formsRepository = $extensionServices['forms'] ?? null;
            // Guard dynamic service before calling optional list API.
            if (is_object($formsRepository) && method_exists($formsRepository, 'listAll')) {
                /** @var mixed $rows */
                $rows = $formsRepository->listAll();
                // Only array row sets can be normalized into panel form metadata.
                if (is_array($rows)) {
                    $items = [];
                    // Filter form rows down to enabled entries with valid slugs.
                    foreach ($rows as $row) {
                        if (!is_array($row) || empty($row['enabled'])) {
                            continue;
                        }

                        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
                        // Skip malformed slugs to keep downstream route generation safe.
                        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                            continue;
                        }

                        $name = trim((string) ($row['name'] ?? ''));
                        // Empty display names fall back to slug so select labels stay readable.
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
        // Lazily instantiate to avoid unnecessary filesystem setup in read-only requests.
        if (!$this->extensionStorageProvisioner instanceof StorageProvisioner) {
            $this->extensionStorageProvisioner = new StorageProvisioner($this->root);
        }

        return $this->extensionStorageProvisioner;
    }

    /**
     * Returns the extension bootstrap-contract resolver on first use.
     */
    private function extensionBootstrap(): Bootstrap
    {
        // Bootstrap resolver is cached because it is stateless and reused frequently.
        if (!$this->extensionBootstrap instanceof Bootstrap) {
            $this->extensionBootstrap = new Bootstrap();
        }

        return $this->extensionBootstrap;
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
        $contract = $this->extensionBootstrap()->resolve($this->root, $extensionName, $manifest);
        // Provisioning requires a valid bootstrap contract for storage declarations.
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
        $contract = $this->extensionBootstrap()->resolve($this->root, $extensionName, $manifest);
        // Cleanup requires a valid bootstrap contract for storage declarations.
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
        // Archive helper is initialized on demand for upload/export paths only.
        if (!$this->archivePackages instanceof ArchivePackage) {
            $this->archivePackages = new ArchivePackage($this->root);
        }

        return $this->archivePackages;
    }

    /**
     * Returns the extension-scaffold service on first use.
     */
    private function extensionScaffold(): Scaffold
    {
        // Scaffold generator is created lazily because create workflow is infrequent.
        if (!$this->extensionScaffold instanceof Scaffold) {
            $this->extensionScaffold = new Scaffold();
        }

        return $this->extensionScaffold;
    }

    /**
     * Returns the package-install workflow service on first use.
     */
    private function packageInstaller(): ArchiveInstall
    {
        // Package installer is built once and reused for all upload operations.
        if (!$this->packageInstaller instanceof ArchiveInstall) {
            $this->packageInstaller = new ArchiveInstall(
                $this->input,
                new Upload(),
                $this->archivePackages()
            );
        }

        return $this->packageInstaller;
    }

    /**
     * Returns the directory-tree helper on first use.
     */
    private function directoryTree(): ArchiveDelete
    {
        // Tree-delete helper is cached for cleanup in uninstall and failed installs.
        if (!$this->directoryTree instanceof ArchiveDelete) {
            $this->directoryTree = new ArchiveDelete();
        }

        return $this->directoryTree;
    }
}
