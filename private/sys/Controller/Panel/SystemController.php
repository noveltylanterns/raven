<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/SystemController.php
 * Split panel system controller for configuration, routing, update, logs, themes, and extensions.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Database\ConnectionFactory;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\TaxonomyLookupRepository;
use Raven\Core\Repository\TaxonomySetRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Lib\Archive\ArchivePackageService;
use Raven\Lib\Archive\PackageInstallWorkflowService;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\PanelAccess;
use Raven\Lib\Config\ConfigEditorNormalizer;
use Raven\Lib\Config\ConfigEditorSchemaService;
use Raven\Lib\Config\ConfigSnapshotSanitizer;
use Raven\Lib\Config\PanelConfigDefaultsService;
use Raven\Lib\Config\PanelConfigFieldPolicyService;
use Raven\Lib\Extension\ExtensionBootstrapContractResolver;
use Raven\Lib\Extension\ExtensionCatalogService;
use Raven\Lib\Extension\ExtensionPermissionCatalogService;
use Raven\Lib\Extension\ExtensionScaffoldService;
use Raven\Lib\Extension\ExtensionStateStore;
use Raven\Lib\Extension\ExtensionStorageCleaner;
use Raven\Lib\Extension\ExtensionStorageProvisioner;
use Raven\Lib\Filesystem\DirectoryTreeService;
use Raven\Lib\Log\EventLogger;
use Raven\Lib\Panel\PanelEditorTabService;
use Raven\Lib\Panel\PanelRoutingPreviewService;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\ChannelRoutePolicy;
use Raven\Lib\Routing\RouteConfigService;
use Raven\Lib\Routing\RoutingInventoryBuilder;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\Update\GitCommandRunner;
use Raven\Lib\Update\UpdateSourceResolver;
use Raven\Lib\Update\UpdateWorkflowService;
use Raven\Lib\View\PublicThemeRegistry;
use Raven\Lib\View\ThemeCatalogService;
use Raven\Lib\View\ThemeCloneService;
use Raven\Lib\View\ThemeFallbackRenderer;
use Raven\Lib\View\ThemeScaffoldService;
use ZipArchive;

use function Raven\Lib\Support\redirect;

/**
 * Handles split panel system-management routes.
 *
 * Owns the remaining panel administration seam: configuration, updater,
 * routing inventory, event logs, public-theme management, and extension
 * management. The shared auth/flash/render state stays centralized in
 * RequestContext, while this controller keeps the system-specific services and
 * filesystem workflows localized to one place.
 */
final class SystemController
{
    private RequestContext $context;
    private Config $config;
    private InputSanitizer $input;
    private string $root;
    private ChannelRepository $channelRepo;
    private PageRepository $pageRepo;
    private RedirectRepository $redirectRepo;
    private UserRepository $userRepo;
    /** @var Closure(): TaxonomySetRepository */
    private Closure $categorySetRepoResolver;
    private ?TaxonomySetRepository $categorySetRepo = null;
    /** @var Closure(): TaxonomySetRepository */
    private Closure $tagSetRepoResolver;
    private ?TaxonomySetRepository $tagSetRepo = null;
    /** @var Closure(): TaxonomyLookupRepository */
    private Closure $taxonomyLookupRepoResolver;
    private ?TaxonomyLookupRepository $taxonomyLookupRepo = null;
    /** @var Closure(): EventLogger */
    private Closure $loggerResolver;
    private ?EventLogger $logger = null;
    /** @var Closure(string): array<string, mixed> */
    private Closure $extensionServicesFor;
    private LoginIdentifierResolver $identifierResolver;
    private ?ArchivePackageService $archivePackages = null;
    private ?ExtensionStateStore $extensionStateStore = null;
    private ?ExtensionScaffoldService $extensionScaffoldService = null;
    private ?ThemeScaffoldService $themeScaffoldService = null;
    private ?ConfigEditorNormalizer $configEditorNormalizer = null;
    private ?PanelConfigDefaultsService $panelConfigDefaultsService = null;
    private ?RoutingInventoryBuilder $routingInventoryBuilder = null;
    private ?ExtensionPermissionCatalogService $extensionPermissionCatalogService = null;
    private ?ExtensionStorageProvisioner $extensionStorageProvisioner = null;
    private ?ExtensionBootstrapContractResolver $extensionBootstrapContractResolver = null;
    private ?ConfigSnapshotSanitizer $configSnapshotSanitizer = null;
    private ?ThemeCloneService $themeCloneService = null;
    private ?ThemeFallbackRenderer $publicFallbackRenderer = null;
    private ?SiteContextBuilder $siteContextBuilder = null;
    private ?ConfigEditorSchemaService $configEditorSchemaService = null;
    private ?ProfileContactService $profileContactService = null;
    private ?RouteConfigService $routeConfigService = null;
    private ?ExtensionCatalogService $extensionCatalogService = null;
    private ?PanelEditorTabService $panelEditorTabService = null;
    private ?PanelRoutingPreviewService $panelRoutingPreviewService = null;
    private ?ThemeCatalogService $themeCatalogService = null;
    private ?PanelConfigFieldPolicyService $panelConfigFieldPolicyService = null;
    private ?PackageInstallWorkflowService $packageInstallWorkflowService = null;
    private ?DirectoryTreeService $directoryTreeService = null;
    private ?GitCommandRunner $gitCommandRunner = null;
    private ?UpdateSourceResolver $updateSourceResolver = null;
    private ?UpdateWorkflowService $updateWorkflowService = null;

    /**
     * @param RequestContext $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for filesystem-backed admin workflows.
     * @param ChannelRepository $channelRepo Channel repository for config and routing views.
     * @param PageRepository $pageRepo Page repository for routing inventory rows.
     * @param RedirectRepository $redirectRepo Redirect repository for routing inventory rows.
     * @param UserRepository $userRepo User repository for routing inventory rows.
     * @param callable(): TaxonomySetRepository $categorySetRepoResolver Lazy category-set repository resolver.
     * @param callable(): TaxonomySetRepository $tagSetRepoResolver Lazy tag-set repository resolver.
     * @param callable(): TaxonomyLookupRepository $taxonomyLookupRepoResolver Lazy taxonomy lookup resolver.
     * @param callable(): EventLogger $loggerResolver Lazy event logger resolver.
     * @param callable(string): array<string, mixed> $extensionServicesFor Lazy per-extension services resolver.
     * @return void
     */
    public function __construct(
        RequestContext $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        ChannelRepository $channelRepo,
        PageRepository $pageRepo,
        RedirectRepository $redirectRepo,
        UserRepository $userRepo,
        callable $categorySetRepoResolver,
        callable $tagSetRepoResolver,
        callable $taxonomyLookupRepoResolver,
        callable $loggerResolver,
        callable $extensionServicesFor
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->channelRepo = $channelRepo;
        $this->pageRepo = $pageRepo;
        $this->redirectRepo = $redirectRepo;
        $this->userRepo = $userRepo;
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->taxonomyLookupRepoResolver = Closure::fromCallable($taxonomyLookupRepoResolver);
        $this->loggerResolver = Closure::fromCallable($loggerResolver);
        $this->extensionServicesFor = Closure::fromCallable($extensionServicesFor);
        $this->identifierResolver = new LoginIdentifierResolver();
    }

    /**
     * Renders the configuration editor.
     *
     * @return void
     */
    public function configuration(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('configuration', 'view')) {
            return;
        }

        $configSnapshot = $this->config->all();
        $configSnapshot = $this->removeSqliteDatabaseFilesConfig($configSnapshot);
        $configSnapshot = $this->applyConfigEditorDefaults($configSnapshot);
        $activeConfigTab = $this->normalizeConfigEditorTab($_GET['tab'] ?? 'basic');

        $this->context->renderPanel('panel/configuration', [
            'canManageConfiguration' => $this->context->auth()->canManageConfiguration(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'configuration',
            'configSnapshot' => $configSnapshot,
            'configFields' => $this->flattenConfigFields($configSnapshot),
            'channelOptions' => $this->channelRepo->listRoutingOptions(),
            'categorySetOptions' => $this->categorySetRepo()->listOptions(),
            'tagSetOptions' => $this->tagSetRepo()->listOptions(),
            'activeConfigTab' => $activeConfigTab,
        ]);
    }

    /**
     * Saves configuration values from the configuration editor.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function configurationSave(array $post): void
    {
        $this->context->requirePanelLogin();
        $activeConfigTab = $this->normalizeConfigEditorTab($post['_config_tab'] ?? 'basic');

        if (!$this->context->requireRoutePermissionOrForbidden('configuration', 'edit')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        /** @var mixed $rawConfigValues */
        $rawConfigValues = $post['config_values'] ?? [];
        if (!is_array($rawConfigValues)) {
            $this->context->flash('error', 'Invalid configuration payload.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        $currentConfig = $this->config->all();
        $currentConfig = $this->removeSqliteDatabaseFilesConfig($currentConfig);
        $currentConfig = $this->applyConfigEditorDefaults($currentConfig);
        $fields = $this->flattenConfigFields($currentConfig);
        $nextConfig = $currentConfig;

        try {
            foreach ($fields as $field) {
                /** @var array<int, string> $segments */
                $segments = $field['segments'];
                $path = (string) $field['path'];
                if (str_starts_with($path, 'user.contact.')) {
                    continue;
                }

                $type = (string) $field['type'];
                if ($path === 'feed.channels') {
                    $rawValue = is_array($rawConfigValues['feed']['channels'] ?? null)
                        ? $rawConfigValues['feed']['channels']
                        : [];
                    $normalized = $this->panelConfigFieldPolicyService()->normalizeFeedChannelsValue(
                        $rawValue,
                        $this->channelRepo->listRoutingOptions()
                    );
                    $this->setNestedConfigValue($nextConfig, $segments, $normalized);
                    continue;
                }

                $rawValue = $this->readNestedConfigValue($rawConfigValues, $segments);
                $normalized = $this->normalizeConfigFieldValue($path, $type, $rawValue, $nextConfig);
                $this->setNestedConfigValue($nextConfig, $segments, $normalized);
            }
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        // Keep critical bootstrap keys valid before replacing the config file.
        $domain = $this->input->text((string) ($nextConfig['site']['domain'] ?? ''), 200);
        $panelPath = $this->input->slug((string) ($nextConfig['panel']['path'] ?? ''));
        if ($domain === '' || $panelPath === null) {
            $this->context->flash('error', 'site.domain and panel.path are required.');
            redirect($this->configurationUrlForTab($activeConfigTab));
        }

        $nextConfig['site']['domain'] = $domain;
        $nextConfig['panel']['path'] = $panelPath;
        $nextConfig['user'] = is_array($nextConfig['user'] ?? null) ? $nextConfig['user'] : [];
        $nextConfig['user']['contact'] = $this->normalizeSubmittedProfileContactOptionsConfig(
            $post['profile_contact_options'] ?? null
        );

        $nextConfig = $this->applyConfigEditorDefaults($nextConfig);
        $nextConfig = $this->removeSqliteDatabaseFilesConfig($nextConfig);

        $this->config->replace($nextConfig);
        $this->config->save();

        $this->context->flash('success', 'Configuration saved.');
        redirect($this->configurationUrlForTab($activeConfigTab));
    }

    /**
     * Renders the updater page with a live source comparison.
     *
     * @return void
     */
    public function update(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('update', 'view')) {
            return;
        }

        $source = $this->updateSourceResolver()->fromConfig($this->config->all());
        $result = $this->updateWorkflowService()->compare($source);
        $this->renderUpdatePage($source, $result, null, null, false);
    }

    /**
     * Handles update actions and persists the selected updater source config.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function updateAction(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('update', 'view')) {
            return;
        }

        $source = $this->updateSourceResolver()->fromPost(
            $post,
            $this->updateSourceResolver()->fromConfig($this->config->all())
        );
        $allowOverwrite = ((string) ($post['allow_overwrite'] ?? '')) === '1';
        $error = null;
        $success = null;

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $error = 'Invalid CSRF token.';
            $result = $this->updateWorkflowService()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        $sourceErrors = $this->updateSourceResolver()->validationErrors($source);
        if ($sourceErrors !== []) {
            $error = implode(' ', $sourceErrors);
            $result = $this->updateWorkflowService()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        try {
            $this->persistUpdateSourceConfig($source);
        } catch (\RuntimeException $exception) {
            $error = 'Failed to save updater source settings: ' . $exception->getMessage();
            $result = $this->updateWorkflowService()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        $action = strtolower(trim((string) ($post['update_action'] ?? 'check')));
        if (!in_array($action, ['check', 'dry_run', 'update_now'], true)) {
            $action = 'check';
        }

        $result = match ($action) {
            'dry_run' => $this->updateWorkflowService()->dryRun($source, $allowOverwrite),
            'update_now' => $this->updateWorkflowService()->update($source, $allowOverwrite),
            default => $this->updateWorkflowService()->compare($source),
        };

        if ((bool) ($result['ok'] ?? false)) {
            $success = trim((string) ($result['message'] ?? ''));
        } else {
            $error = trim((string) ($result['message'] ?? 'Update action failed.'));
        }

        $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
    }

    /**
     * Renders the routing inventory page.
     *
     * @return void
     */
    public function routing(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $routeRows = $this->routingRowsForPanel();
        $summary = [
            'total' => count($routeRows),
            'page' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'page')),
            'channel' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'channel')),
            'redirect' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'redirect')),
            'conflicts' => count(array_filter($routeRows, static fn (array $row): bool => !empty($row['is_conflict']))),
        ];
        $initialSearch = $this->input->text(is_string($_GET['search'] ?? null) ? $_GET['search'] : null, 200);

        $this->context->renderPanel('panel/routing', [
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'routing',
            'pageTitle' => 'Routing Table',
            'routeRows' => $routeRows,
            'routeSummary' => $summary,
            'initialSearch' => $initialSearch,
        ]);
    }

    /**
     * Exports routing inventory rows as CSV.
     *
     * @return void
     */
    public function routingExport(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $rows = $this->routingRowsForPanel();
        $filename = 'routing-inventory-' . gmdate('Ymd-His') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        fputcsv($stream, ['Type', 'Title', 'Public URL', 'Target URL', 'Status', 'Notes', 'Conflict']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['type_label'] ?? ''),
                (string) ($row['source_label'] ?? ''),
                (string) ($row['public_url'] ?? ''),
                (string) ($row['target_url'] ?? ''),
                (string) ($row['status_label'] ?? ''),
                (string) ($row['notes'] ?? ''),
                !empty($row['is_conflict']) ? 'Yes' : 'No',
            ]);
        }

        fclose($stream);
    }

    /**
     * Renders the public-theme manager.
     *
     * @return void
     */
    public function themes(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        $this->context->renderPanel('panel/themes', [
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'themes',
            'themes' => $this->listPublicThemesForPanel(),
            'activeTheme' => $this->activePublicThemeSlug(),
            'themeOptions' => PublicThemeRegistry::options($this->publicThemesRoot()),
        ]);
    }

    /**
     * Persists the active public theme selection.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function themesEnable(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'edit')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            redirect($this->context->panelUrl('/themes'));
        }

        $availableThemes = $this->publicThemeOptions();
        if (!isset($availableThemes[$themeSlug])) {
            $this->context->flash('error', 'Theme "' . $themeSlug . '" is not available.');
            redirect($this->context->panelUrl('/themes'));
        }

        try {
            $this->config->set('site.theme', $themeSlug);
            $this->config->save();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Failed to update active theme: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/themes'));
        }

        $this->context->flash('success', 'Active public theme set to "' . ($availableThemes[$themeSlug] ?? $themeSlug) . '".');
        redirect($this->context->panelUrl('/themes'));
    }

    /**
     * Creates a new public-theme scaffold.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function themesCreate(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themeName = trim((string) $this->input->text($post['name'] ?? null, 120));
        if ($themeName === '') {
            $this->context->flash('error', 'Theme name is required.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['slug'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->context->flash('error', 'Theme slug must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->context->panelUrl('/themes'));
        }

        $parentTheme = strtolower(trim((string) $this->input->text($post['parent_theme'] ?? null, 80)));
        if ($parentTheme !== '' && !$this->isSafePublicThemeSlug($parentTheme)) {
            $this->context->flash('error', 'Parent theme slug is invalid.');
            redirect($this->context->panelUrl('/themes'));
        }

        $cloneTheme = strtolower(trim((string) $this->input->text($post['clone_theme'] ?? null, 80)));
        if ($cloneTheme !== '' && !$this->isSafePublicThemeSlug($cloneTheme)) {
            $this->context->flash('error', 'Clone-source theme slug is invalid.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themesRoot = $this->publicThemesRoot();
        $themeOptions = PublicThemeRegistry::options($themesRoot);
        $themeManifests = PublicThemeRegistry::manifests($themesRoot);
        if ($parentTheme !== '' && !isset($themeOptions[$parentTheme])) {
            $this->context->flash('error', 'Selected parent theme was not found.');
            redirect($this->context->panelUrl('/themes'));
        }
        if ($cloneTheme !== '' && !isset($themeOptions[$cloneTheme])) {
            $this->context->flash('error', 'Selected clone-source theme was not found.');
            redirect($this->context->panelUrl('/themes'));
        }
        if ($parentTheme === $themeSlug) {
            $this->context->flash('error', 'A child theme cannot use itself as parent.');
            redirect($this->context->panelUrl('/themes'));
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';
        $generatePackageFile = isset($post['generate_package']) && (string) $post['generate_package'] === '1';
        $setActive = isset($post['set_active']) && (string) $post['set_active'] === '1';
        $themePath = $themesRoot . '/' . $themeSlug;
        if (file_exists($themePath)) {
            $this->context->flash('error', 'A theme directory with this slug already exists.');
            redirect($this->context->panelUrl('/themes'));
        }

        $isChildTheme = $parentTheme !== '';
        $resolvedParentTheme = $parentTheme;
        if ($cloneTheme !== '' && !$isChildTheme) {
            $cloneManifest = $themeManifests[$cloneTheme] ?? null;
            if (is_array($cloneManifest) && !empty($cloneManifest['is_child_theme'])) {
                $cloneParent = strtolower(trim((string) ($cloneManifest['parent_theme'] ?? '')));
                if ($cloneParent !== '' && $cloneParent !== $themeSlug && isset($themeOptions[$cloneParent])) {
                    $isChildTheme = true;
                    $resolvedParentTheme = $cloneParent;
                }
            }
        }

        try {
            if ($cloneTheme !== '') {
                $clonePath = $themesRoot . '/' . $cloneTheme;
                $this->copyDirectoryRecursively($clonePath, $themePath);
                $this->writePublicThemeManifest(
                    $themePath . '/theme.json',
                    [
                        'name' => $themeName,
                        'is_child_theme' => $isChildTheme,
                        'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                    ]
                );

                if ($generateAgentsFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/AGENTS.md',
                        $this->publicThemeAgentsFileContent([
                            'slug' => $themeSlug,
                            'name' => $themeName,
                            'is_child_theme' => $isChildTheme,
                            'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                        ])
                    );
                }
                if ($generateComposerFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/composer.json',
                        $this->publicThemeComposerFileContent([
                            'slug' => $themeSlug,
                            'name' => $themeName,
                        ])
                    );
                }
                if ($generatePackageFile) {
                    $this->writePublicThemeScaffoldFile(
                        $themePath . '/package.json',
                        $this->publicThemePackageFileContent([
                            'slug' => $themeSlug,
                            'name' => $themeName,
                        ])
                    );
                }
            } else {
                $this->createPublicThemeSkeleton(
                    $themePath,
                    [
                        'slug' => $themeSlug,
                        'name' => $themeName,
                        'is_child_theme' => $isChildTheme,
                        'parent_theme' => $isChildTheme ? $resolvedParentTheme : '',
                    ],
                    $generateAgentsFile,
                    $generateComposerFile,
                    $generatePackageFile
                );
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeDirectoryRecursively($themePath);
            $this->context->flash('error', 'Failed to create theme scaffold: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/themes'));
        }

        if ($setActive) {
            try {
                $this->config->set('site.theme', $themeSlug);
                $this->config->save();
            } catch (\RuntimeException $exception) {
                $this->directoryTreeService()->removeDirectoryRecursively($themePath);
                $this->context->flash('error', 'Theme scaffold created, but activation failed: ' . $exception->getMessage());
                redirect($this->context->panelUrl('/themes'));
            }
        }

        $message = 'Theme scaffold created at public/theme/' . $themeSlug . '/';
        if ($cloneTheme !== '') {
            $message .= ' (cloned from "' . $cloneTheme . '")';
        }
        $message .= $setActive ? ' and activated.' : '.';
        if ($generateAgentsFile || $generateComposerFile || $generatePackageFile) {
            $generated = [];
            if ($generateAgentsFile) {
                $generated[] = 'AGENTS.md';
            }
            if ($generateComposerFile) {
                $generated[] = 'composer.json';
            }
            if ($generatePackageFile) {
                $generated[] = 'package.json';
            }
            $message .= ' Generated: ' . implode(', ', $generated) . '.';
        }

        $this->context->flash('success', $message);
        redirect($this->context->panelUrl('/themes'));
    }

    /**
     * Uploads a zipped public-theme package.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @param array<string, mixed> $files Uploaded file payload.
     * @return void
     */
    public function themesUpload(array $post, array $files): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            redirect($this->context->panelUrl('/themes'));
        }

        if (!class_exists(ZipArchive::class)) {
            $this->context->flash('error', 'Theme upload requires the PHP zip extension.');
            redirect($this->context->panelUrl('/themes'));
        }

        $upload = $this->packageInstallWorkflowService()->validateZipUploadPayload(
            $files['theme_archive'] ?? null,
            'Theme archive',
            'Themes'
        );
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->context->flash('error', (string) ($upload['error'] ?? 'Theme upload failed.'));
            redirect($this->context->panelUrl('/themes'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'theme.zip');
        $derivedThemeSlug = $this->packageInstallWorkflowService()->themeSlugFromArchiveManifest($tmpPath);

        $slugResult = $this->packageInstallWorkflowService()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedThemeSlug ?? $this->themeSlugFromArchiveFilename($name),
            fn (string $slug): bool => $this->isSafePublicThemeSlug($slug),
            fn (string $slug): bool => $this->isStockPublicThemeSlug($slug),
            fn (string $slug): ?string => $this->nextAvailablePublicThemeSlug($slug),
            fn (string $slug): bool => file_exists($this->publicThemesRoot() . '/' . $slug),
            'Theme',
            'Theme slug must use lowercase letters, numbers, underscores, or dashes.'
        );
        if (!(bool) ($slugResult['ok'] ?? false)) {
            $slugError = (string) ($slugResult['error'] ?? 'Failed to resolve theme slug.');
            if (
                trim((string) ($post['upload_slug'] ?? '')) === ''
                && $derivedThemeSlug === null
                && $this->themeSlugFromArchiveFilename($archiveName) === null
            ) {
                $slugError = 'Theme upload failed: theme.json must include a valid "slug" value or use Slug Override.';
            }

            $this->context->flash('error', $slugError);
            redirect($this->context->panelUrl('/themes'));
        }
        $themeSlug = (string) ($slugResult['name'] ?? '');

        $themesRoot = $this->publicThemesRoot();
        if (!is_dir($themesRoot) && !mkdir($themesRoot, 0775, true) && !is_dir($themesRoot)) {
            $this->context->flash('error', 'Failed to initialize public/theme directory.');
            redirect($this->context->panelUrl('/themes'));
        }

        $targetDirectory = $themesRoot . '/' . $themeSlug;
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->context->flash('error', 'Failed to create theme directory.');
            redirect($this->context->panelUrl('/themes'));
        }

        $extractError = $this->packageInstallWorkflowService()->extractIntoTarget(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTreeService()->removeDirectoryRecursively($directory);
            },
            'theme'
        );
        if (is_string($extractError)) {
            $this->context->flash('error', $extractError);
            redirect($this->context->panelUrl('/themes'));
        }

        $flattenError = $this->packageInstallWorkflowService()->flattenSingleRootDirectory($targetDirectory);
        if (is_string($flattenError)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->context->flash('error', $flattenError);
            redirect($this->context->panelUrl('/themes'));
        }

        $manifestPath = $targetDirectory . '/theme.json';
        if (!is_file($manifestPath)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->context->flash('error', 'Theme upload failed: archive must include theme.json at archive root.');
            redirect($this->context->panelUrl('/themes'));
        }

        $manifests = PublicThemeRegistry::manifests($themesRoot);
        if (!isset($manifests[$themeSlug])) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->context->flash('error', 'Theme upload failed: theme.json is missing required/valid metadata.');
            redirect($this->context->panelUrl('/themes'));
        }

        $message = 'Theme uploaded to public/theme/' . $themeSlug . '/. Enable it from the Installed Themes list when ready.';
        if ((bool) ($slugResult['renamed'] ?? false)) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }

        $this->context->flash('success', $message);
        redirect($this->context->panelUrl('/themes'));
    }

    /**
     * Exports an installed public theme as a ZIP archive.
     *
     * @param array<string, mixed> $query Query-string payload.
     * @return void
     */
    public function themesExport(array $query): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        if (!class_exists(ZipArchive::class)) {
            $this->context->flash('error', 'Theme export requires the PHP zip extension.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($query['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themePath = $this->publicThemesRoot() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->context->flash('error', 'Theme directory was not found on disk.');
            redirect($this->context->panelUrl('/themes'));
        }

        try {
            $archivePath = $this->archivePackages()->buildZipArchiveFromDirectory($themePath, $themeSlug);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Theme export failed: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/themes'));
        }

        $downloadFilename = 'theme-' . $themeSlug . '-' . gmdate('Ymd-His') . '.zip';
        $this->archivePackages()->streamDownloadFile($archivePath, $downloadFilename, 'application/zip');
    }

    /**
     * Uninstalls a non-active, non-stock public theme.
     *
     * @param array<string, mixed> $post Submitted form payload.
     * @return void
     */
    public function themesUninstall(array $post): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'uninstall')) {
            return;
        }

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['theme'] ?? null, 80)));
        if (!$this->isSafePublicThemeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            redirect($this->context->panelUrl('/themes'));
        }

        if ($this->isStockPublicThemeSlug($themeSlug)) {
            $this->context->flash('error', 'Stock themes cannot be uninstalled.');
            redirect($this->context->panelUrl('/themes'));
        }

        $themePath = $this->publicThemesRoot() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->context->flash('error', 'Theme directory was not found on disk.');
            redirect($this->context->panelUrl('/themes'));
        }

        if ($this->activePublicThemeSlug() === $themeSlug) {
            $this->context->flash('error', 'Active theme cannot be uninstalled. Enable another theme first.');
            redirect($this->context->panelUrl('/themes'));
        }

        $this->directoryTreeService()->removeDirectoryRecursively($themePath);
        if (is_dir($themePath)) {
            $this->context->flash('error', 'Failed to uninstall theme directory from disk.');
            redirect($this->context->panelUrl('/themes'));
        }

        $this->context->flash('success', 'Theme "' . $themeSlug . '" uninstalled.');
        redirect($this->context->panelUrl('/themes'));
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
            $extensions = $this->listExtensionsForPanel();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            $extensions = [];
        }

        $this->context->renderPanel('panel/extensions', [
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'extensions',
            'extensions' => $extensions,
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
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->isSafeExtensionDirectoryName((string) $extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($extensionPath);
        if (!($manifest['valid'] ?? false)) {
            $enabledMap = $this->loadExtensionStateMap();
            if (isset($enabledMap[$extensionName])) {
                unset($enabledMap[$extensionName]);
                $this->saveExtensionStateMap($enabledMap);
            }

            $reason = (string) ($manifest['invalid_reason'] ?? 'Invalid extension metadata.');
            $this->context->flash('error', 'Extension is invalid: ' . $reason);
            redirect($this->context->panelUrl('/extensions'));
        }

        $enabledRaw = strtolower((string) $this->input->text($post['enabled'] ?? null, 10));
        if (!in_array($enabledRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            $this->context->flash('error', 'Invalid extension toggle value.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $enable = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

        try {
            if ($enable) {
                $this->provisionEnabledExtensionStorage((string) $extensionName, $manifest);
            }

            $enabledMap = $this->loadExtensionStateMap();
            if ($enable) {
                $enabledMap[(string) $extensionName] = true;
            } else {
                unset($enabledMap[(string) $extensionName]);
            }

            $this->saveExtensionStateMap($enabledMap);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        $this->context->flash('success', 'Extension "' . $extensionName . '" ' . ($enable ? 'enabled' : 'disabled') . '.');
        redirect($this->context->panelUrl('/extensions'));
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
            redirect($this->context->panelUrl('/extensions'));
        }

        $this->context->flash('error', 'Extension permission levels are managed in Groups > Permissions.');
        redirect($this->context->panelUrl('/extensions'));
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
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = $this->input->text($post['extension'] ?? null, 120);
        if (!$this->isSafeExtensionDirectoryName((string) $extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($extensionPath);
        $isStockExtension = $this->isStockExtensionDirectory((string) $extensionName);

        $enabledMap = $this->loadExtensionStateMap();
        $permissionMap = $this->loadExtensionPermissionMap();
        $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
        if (!empty($enabledMap[$extensionName])) {
            $this->context->flash('error', 'Disable the extension before uninstalling it.');
            redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $this->deleteExtensionStorage((string) $extensionName, $manifest);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Failed to uninstall extension storage: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        if ($isStockExtension) {
            $this->context->flash('success', 'Stock extension "' . $extensionName . '" data purged. Bundled extension files were kept.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $this->directoryTreeService()->removeDirectoryRecursively($extensionPath);
        if (is_dir($extensionPath)) {
            $this->context->flash('error', 'Failed to uninstall extension directory from disk.');
            redirect($this->context->panelUrl('/extensions'));
        }

        if (
            isset($enabledMap[$extensionName])
            || isset($permissionMap[$extensionName])
            || isset($permissionBitsMap[$extensionName])
        ) {
            unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
            try {
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            } catch (\RuntimeException $exception) {
                $this->context->flash('error', 'Extension uninstalled, but state cleanup failed: ' . $exception->getMessage());
                redirect($this->context->panelUrl('/extensions'));
            }
        }

        $this->context->flash('success', 'Extension "' . $extensionName . '" uninstalled.');
        redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Uploads one zipped extension package.
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
            redirect($this->context->panelUrl('/extensions'));
        }

        if (!class_exists(ZipArchive::class)) {
            $this->context->flash('error', 'Extension upload requires the PHP zip extension.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $upload = $this->packageInstallWorkflowService()->validateZipUploadPayload(
            $files['extension_archive'] ?? null,
            'Extension archive',
            'Extensions'
        );
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->context->flash('error', (string) ($upload['error'] ?? 'Extension upload failed.'));
            redirect($this->context->panelUrl('/extensions'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'extension.zip');
        $derivedExtensionSlug = $this->packageInstallWorkflowService()->extensionSlugFromArchiveManifest($tmpPath);

        $nameResult = $this->packageInstallWorkflowService()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedExtensionSlug,
            fn (string $name): bool => $this->isSafeExtensionDirectoryName($name),
            fn (string $name): bool => $this->isStockExtensionDirectory($name),
            fn (string $name): ?string => $this->nextAvailableExtensionDirectoryName($name),
            fn (string $name): bool => file_exists($this->extensionsBasePath() . '/' . $name),
            'Extension',
            'Extension directory must use lowercase letters, numbers, underscores, or dashes.'
        );
        if (!(bool) ($nameResult['ok'] ?? false)) {
            $nameError = (string) ($nameResult['error'] ?? 'Failed to resolve extension directory name.');
            if (trim((string) ($post['upload_slug'] ?? '')) === '' && $derivedExtensionSlug === null) {
                $nameError = 'Extension upload failed: ext.json must include a valid "slug" value.';
            }

            $this->context->flash('error', $nameError);
            redirect($this->context->panelUrl('/extensions'));
        }
        $extensionName = (string) ($nameResult['name'] ?? '');

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        $targetDirectory = $this->extensionsBasePath() . '/' . $extensionName;
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->context->flash('error', 'Failed to create extension directory.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $extractError = $this->packageInstallWorkflowService()->extractIntoTarget(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTreeService()->removeDirectoryRecursively($directory);
            },
            'extension'
        );
        if (is_string($extractError)) {
            $this->context->flash('error', $extractError);
            redirect($this->context->panelUrl('/extensions'));
        }

        $flattenError = $this->packageInstallWorkflowService()->flattenSingleRootDirectory($targetDirectory);
        if (is_string($flattenError)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->context->flash('error', $flattenError);
            redirect($this->context->panelUrl('/extensions'));
        }

        $manifest = $this->readExtensionManifest($targetDirectory);
        if (!($manifest['valid'] ?? false)) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $reason = (string) ($manifest['invalid_reason'] ?? 'Missing required extension metadata.');
            $this->context->flash('error', 'Extension upload failed: ' . $reason);
            redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeDirectoryRecursively($targetDirectory);
            $this->context->flash('error', 'Extension upload failed: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        $message = 'Extension uploaded to private/ext/' . $extensionName . '/. It is disabled by default.';
        if ((bool) ($nameResult['renamed'] ?? false)) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }

        $this->context->flash('success', $message);
        redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Exports one installed extension directory as a ZIP archive.
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

        if (!class_exists(ZipArchive::class)) {
            $this->context->flash('error', 'Extension export requires the PHP zip extension.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim((string) $this->input->text($query['extension'] ?? null, 120)));
        if (!$this->isSafeExtensionDirectoryName($extensionName)) {
            $this->context->flash('error', 'Invalid extension identifier.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (!is_dir($extensionPath)) {
            $this->context->flash('error', 'Extension directory was not found on disk.');
            redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $archivePath = $this->archivePackages()->buildZipArchiveFromDirectory($extensionPath, $extensionName);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Extension export failed: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        $downloadFilename = 'extension-' . $extensionName . '-' . gmdate('Ymd-His') . '.zip';
        $this->archivePackages()->streamDownloadFile($archivePath, $downloadFilename, 'application/zip');
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
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionName = strtolower(trim((string) $this->input->text($post['extension'] ?? null, 120)));
        if ($extensionName === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $extensionName) !== 1) {
            $this->context->flash('error', 'Extension directory must use lowercase letters, numbers, underscores, or dashes.');
            redirect($this->context->panelUrl('/extensions'));
        }

        if ($this->isStockExtensionDirectory($extensionName)) {
            $this->context->flash('error', 'That extension directory name is reserved by a stock extension.');
            redirect($this->context->panelUrl('/extensions'));
        }

        $displayName = $this->input->text($post['name'] ?? null, 120);
        if ($displayName === '') {
            $this->context->flash('error', 'Extension name is required.');
            redirect($this->context->panelUrl('/extensions'));
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
                redirect($this->context->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($homepageRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->context->flash('error', 'Author URL must use http or https.');
                redirect($this->context->panelUrl('/extensions'));
            }

            $homepage = $homepageRaw;
        }

        $docsRaw = trim((string) $this->input->text($post['docs'] ?? null, 400));
        $docs = '';
        if ($docsRaw !== '') {
            if (filter_var($docsRaw, FILTER_VALIDATE_URL) === false) {
                $this->context->flash('error', 'Documentation URL must be a valid absolute URL.');
                redirect($this->context->panelUrl('/extensions'));
            }

            $scheme = strtolower((string) parse_url($docsRaw, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->context->flash('error', 'Documentation URL must use http or https.');
                redirect($this->context->panelUrl('/extensions'));
            }

            $docs = $docsRaw;
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';

        try {
            $this->ensureExtensionsDirectory();
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        $extensionPath = $this->extensionsBasePath() . '/' . $extensionName;
        if (file_exists($extensionPath)) {
            $this->context->flash('error', 'An extension directory with this name already exists.');
            redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $this->createExtensionSkeleton(
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
            $this->directoryTreeService()->removeDirectoryRecursively($extensionPath);
            $this->context->flash('error', 'Failed to create extension scaffold: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        try {
            $enabledMap = $this->loadExtensionStateMap();
            $permissionMap = $this->loadExtensionPermissionMap();
            $permissionBitsMap = $this->loadExtensionPermissionBitsMap();
            if (
                isset($enabledMap[$extensionName])
                || isset($permissionMap[$extensionName])
                || isset($permissionBitsMap[$extensionName])
            ) {
                unset($enabledMap[$extensionName], $permissionMap[$extensionName], $permissionBitsMap[$extensionName]);
                $this->saveExtensionState($enabledMap, $permissionMap, $permissionBitsMap);
            }
        } catch (\RuntimeException $exception) {
            $this->directoryTreeService()->removeDirectoryRecursively($extensionPath);
            $this->context->flash('error', 'Extension scaffold created, but state finalization failed: ' . $exception->getMessage());
            redirect($this->context->panelUrl('/extensions'));
        }

        $createdFiles = ['ext.json', 'ext.php', 'lib/schema.php'];
        if (in_array($type, ['content', 'module'], true)) {
            $createdFiles[] = 'lib/shortcodes.php';
            $createdFiles[] = 'lib/fields.php';
        }
        if ($type !== 'framework') {
            $createdFiles = array_merge($createdFiles, ['lib/routes_panel.php', 'tpl/panel_index.php']);
        }
        if ($type === 'module') {
            $createdFiles[] = 'lib/routes_public.php';
            $createdFiles[] = 'tpl/public_index.php';
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
            . ($generateAgentsFile ? ', plus AGENTS.md.' : '.')
        );
        redirect($this->context->panelUrl('/extensions'));
    }

    /**
     * Renders the event log viewer.
     *
     * @return void
     */
    public function logs(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'view')) {
            return;
        }

        $severity = $this->input->text($_GET['severity'] ?? null, 10) ?? '';
        $severity = in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '';
        $search = $this->input->text($_GET['search'] ?? null, 200) ?? '';

        $filters = [];
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $perPage = 50;
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $totalItems = $this->logger()->count($filters);
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $requestedPage = $pagination['current'];
        }

        $rows = $this->logger()->query($filters, $perPage, $pagination['offset']);
        $paginationQuery = [];
        if ($severity !== '') {
            $paginationQuery['severity'] = $severity;
        }
        if ($search !== '') {
            $paginationQuery['search'] = $search;
        }

        $this->context->renderPanel('panel/logs', [
            'rows' => $rows,
            'filters' => ['severity' => $severity, 'search' => $search],
            'pagination' => $this->context->panelPaginationViewData('/logs', $pagination, $paginationQuery),
            'totalItems' => $totalItems,
            'loggingEnabled' => $this->logger()->isEnabled('error') || $this->logger()->isEnabled('warn') || $this->logger()->isEnabled('info'),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'logs',
            'pageTitle' => 'Event Log',
            'canClear' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::CONFIGURATION_DELETE),
        ]);
    }

    /**
     * Exports the event log as CSV.
     *
     * @return void
     */
    public function logsExport(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'view')) {
            return;
        }

        $severity = $this->input->text($_GET['severity'] ?? null, 10) ?? '';
        $severity = in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '';
        $search = $this->input->text($_GET['search'] ?? null, 200) ?? '';

        $filters = [];
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $rows = $this->logger()->allForExport($filters);
        $filename = 'event-log-' . gmdate('Ymd-His') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if (!is_resource($stream)) {
            http_response_code(500);
            echo 'Failed to open export stream.';
            return;
        }

        fputcsv($stream, ['ID', 'Logged At', 'Severity', 'Channel', 'Message', 'Context']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['id'] ?? ''),
                (string) ($row['logged_at'] ?? ''),
                (string) ($row['severity'] ?? ''),
                (string) ($row['channel'] ?? ''),
                (string) ($row['message'] ?? ''),
                (string) ($row['context'] ?? ''),
            ]);
        }

        fclose($stream);
    }

    /**
     * Clears all event log entries.
     *
     * @return void
     */
    public function logsClear(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'delete')) {
            return;
        }

        $post = $_POST;
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            redirect($this->context->panelUrl('/logs'));
        }

        $deleted = $this->logger()->clear();
        $this->context->flash('success', 'Event log cleared (' . $deleted . ' ' . ($deleted === 1 ? 'entry' : 'entries') . ' removed).');
        redirect($this->context->panelUrl('/logs'));
    }

    /**
     * Returns extension permission metadata for matching directories.
     *
     * @param array<int, string> $directoryFilter Optional directory whitelist.
     * @return array<string, array{
     *   name: string,
     *   type: string,
     *   default_level: string,
     *   levels: array<int, array{key: string, label: string, bit: int}>
     * }>
     */
    public function extensionPanelPermissionMapForDirectories(array $directoryFilter = []): array
    {
        return $this->extensionCatalogService()->panelPermissionMapForDirectories(
            $directoryFilter,
            fn (string $extensionPath): array => $this->readExtensionManifest($extensionPath)
        );
    }

    /**
     * Renders the active public theme's 404 page with wrapper layout.
     *
     * Unauthenticated panel access and extension permission failures use the
     * public 404 view so the panel URL structure is not exposed to guests.
     *
     * @return void
     */
    public function renderPublicNotFound(): void
    {
        http_response_code(404);

        $renderer = $this->publicFallbackRenderer();
        $activeTheme = $this->activePublicThemeSlug();
        $templateFile = $renderer->resolveTemplateFile('status/404', $activeTheme);
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            return;
        }

        $site = $this->publicSiteDataForNotFound();
        $content = $renderer->renderFile($templateFile, [
            'site' => $site,
        ]);

        $layoutFile = $renderer->resolveTemplateFile('wrapper', $activeTheme);
        if ($layoutFile === null) {
            echo $content;
            return;
        }

        echo $renderer->renderFile($layoutFile, [
            'site' => $site,
            'content' => $content,
        ]);
    }

    /**
     * Returns the category-set repository on first use.
     *
     * @return TaxonomySetRepository Category-set repository.
     */
    private function categorySetRepo(): TaxonomySetRepository
    {
        if ($this->categorySetRepo instanceof TaxonomySetRepository) {
            return $this->categorySetRepo;
        }

        $categorySetRepo = ($this->categorySetRepoResolver)();
        if (!$categorySetRepo instanceof TaxonomySetRepository) {
            throw new \RuntimeException('Panel category-set repository resolver returned an invalid value.');
        }

        $this->categorySetRepo = $categorySetRepo;
        return $this->categorySetRepo;
    }

    /**
     * Returns the tag-set repository on first use.
     *
     * @return TaxonomySetRepository Tag-set repository.
     */
    private function tagSetRepo(): TaxonomySetRepository
    {
        if ($this->tagSetRepo instanceof TaxonomySetRepository) {
            return $this->tagSetRepo;
        }

        $tagSetRepo = ($this->tagSetRepoResolver)();
        if (!$tagSetRepo instanceof TaxonomySetRepository) {
            throw new \RuntimeException('Panel tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $tagSetRepo;
        return $this->tagSetRepo;
    }

    /**
     * Returns the taxonomy lookup repository on first use.
     *
     * @return TaxonomyLookupRepository Taxonomy lookup repository.
     */
    private function taxonomyLookupRepo(): TaxonomyLookupRepository
    {
        if ($this->taxonomyLookupRepo instanceof TaxonomyLookupRepository) {
            return $this->taxonomyLookupRepo;
        }

        $taxonomyLookupRepo = ($this->taxonomyLookupRepoResolver)();
        if (!$taxonomyLookupRepo instanceof TaxonomyLookupRepository) {
            throw new \RuntimeException('Panel taxonomy lookup repository resolver returned an invalid value.');
        }

        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        return $this->taxonomyLookupRepo;
    }

    /**
     * Returns routing-inventory taxonomy data while skipping taxonomy lookup storage
     * entirely when both category and tag public routes are disabled.
     *
     * @param string $categoryPrefix Effective category route prefix.
     * @param string $tagPrefix Effective tag route prefix.
     * @return array{
     *   channel_options: array<int, array<string, mixed>>,
     *   category_options_all: array<int, array<string, mixed>>,
     *   tag_options_all: array<int, array<string, mixed>>,
     *   redirect_rows: array<int, array<string, mixed>>
     * }
     */
    private function routingInventoryTaxonomyOptionSets(string $categoryPrefix, string $tagPrefix): array
    {
        $includeCategories = trim($categoryPrefix) !== '';
        $includeTags = trim($tagPrefix) !== '';
        if (!$includeCategories && !$includeTags) {
            return [
                'channel_options' => $this->channelRepo->listRoutingOptions(),
                'category_options_all' => [],
                'tag_options_all' => [],
                'redirect_rows' => $this->redirectRepo->listAll(),
            ];
        }

        return $this->taxonomyLookupRepo()->listRoutingInventoryData($includeCategories, $includeTags, true);
    }

    /**
     * Returns the event logger on first use.
     *
     * @return EventLogger Event logger service.
     */
    private function logger(): EventLogger
    {
        if ($this->logger instanceof EventLogger) {
            return $this->logger;
        }

        $logger = ($this->loggerResolver)();
        if (!$logger instanceof EventLogger) {
            throw new \RuntimeException('Panel event logger resolver returned an invalid value.');
        }

        $this->logger = $logger;
        return $this->logger;
    }

    /**
     * Normalizes one text-editor option from config/editor payloads.
     */
    private function normalizeBodyTextEditorOption(string $value): string
    {
        $editor = strtolower(trim($value));
        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'tinymce';
    }

    /**
     * Normalizes one global route-separator option.
     */
    private function normalizeGlobalRouteSeparator(string $value): string
    {
        return ChannelRoutePolicy::normalizeGlobalSeparator($value);
    }

    /**
     * Returns the configured global page route mode.
     */
    private function globalPageRouteMode(): string
    {
        return $this->routeConfigService()->globalPageRouteMode();
    }

    /**
     * Returns the effective channel page route mode for one channel row.
     */
    private function effectiveChannelRouteMode(string $channelValue): string
    {
        return $this->routeConfigService()->effectiveChannelRouteMode($channelValue);
    }

    /**
     * Flattens the config tree into scalar field descriptors.
     *
     * @param array<string, mixed> $config Config snapshot to flatten.
     * @param array<int, string> $segments Current path segments during recursion.
     * @return array<int, array{
     *   path: string,
     *   segments: array<int, string>,
     *   label: string,
     *   type: string,
     *   value: string
     * }>
     */
    private function flattenConfigFields(array $config, array $segments = []): array
    {
        return $this->configEditorSchemaService()->flattenFields($config, $segments);
    }

    /**
     * Reads one submitted config value from a nested posted array.
     *
     * @param array<string, mixed> $submitted Posted config payload.
     * @param array<int, string> $segments Nested config path segments.
     * @return string Submitted scalar value.
     */
    private function readNestedConfigValue(array $submitted, array $segments): string
    {
        return $this->configEditorSchemaService()->readNestedValue($submitted, $segments);
    }

    /**
     * Writes one scalar value into a nested config array by path segments.
     *
     * @param array<string, mixed> $config Config array being mutated.
     * @param array<int, string> $segments Nested config path segments.
     * @param mixed $value Normalized value to write.
     * @return void
     */
    private function setNestedConfigValue(array &$config, array $segments, mixed $value): void
    {
        $this->configEditorSchemaService()->setNestedValue($config, $segments, $value);
    }

    /**
     * Casts and validates one submitted config field value by expected type.
     *
     * @param string $path Dotted config key path.
     * @param string $type Expected scalar type.
     * @param string $rawValue Submitted scalar value.
     * @param array<string, mixed> $workingConfig Current config snapshot being normalized.
     * @return mixed Normalized config value.
     */
    private function normalizeConfigFieldValue(string $path, string $type, string $rawValue, array $workingConfig = []): mixed
    {
        return $this->panelConfigFieldPolicyService()->normalizeFieldValue(
            $path,
            $type,
            $rawValue,
            $workingConfig,
            fn (string $value): string => $this->normalizeBodyTextEditorOption($value),
            fn (string $value): string => $this->normalizeGlobalRouteSeparator($value),
            fn (string $theme, bool $allowDefault): ?string => $this->normalizePanelThemeChoice($theme, $allowDefault),
            $this->publicThemeOptions(),
            $this->channelRepo->listRoutingOptions(),
            $this->categorySetRepo()->listOptions(),
            $this->tagSetRepo()->listOptions()
        );
    }

    /**
     * Removes SQLite file-map config from the editable config snapshot.
     *
     * @param array<string, mixed> $config Editable config snapshot.
     * @return array<string, mixed> Sanitized config snapshot.
     */
    private function removeSqliteDatabaseFilesConfig(array $config): array
    {
        return $this->configSnapshotSanitizer()->removeSqliteDatabaseFiles($config);
    }

    /**
     * Applies shared config-editor default keys across core sections.
     *
     * @param array<string, mixed> $config Editable config snapshot.
     * @return array<string, mixed> Config snapshot with enforced defaults.
     */
    private function applyConfigEditorDefaults(array $config): array
    {
        return $this->panelConfigDefaultsService()->apply(
            $config,
            $this->publicThemeOptions(),
            fn (string $theme, bool $allowDefault): ?string => $this->normalizePanelThemeChoice($theme, $allowDefault)
        );
    }

    /**
     * Normalizes one persisted/user-submitted identifier column value.
     *
     * Accepts canonical usernames and email-shaped values.
     *
     * @param string $rawValue Raw identifier value.
     * @return string|null Normalized identifier or null when invalid.
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        return $this->identifierResolver->normalizeUsernameOrEmail($this->input, $rawValue);
    }

    /**
     * Returns one public profile route segment for a user row.
     *
     * @param array<string, mixed> $user User row used for routing inventory.
     * @return string|null Public route segment or null when unavailable.
     */
    private function publicProfileRouteSegmentForUser(array $user): ?string
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        return match ($this->routeConfigService()->profileSelector()) {
            'string' => $this->currentUserString($user),
            'username' => $this->normalizeUserIdentifierValue((string) ($user['username'] ?? '')),
            default => (string) $userId,
        };
    }

    /**
     * Returns the current persisted user string when available.
     *
     * @param array<string, mixed>|null $user User row or null.
     * @return string|null Persisted user string.
     */
    private function currentUserString(?array $user): ?string
    {
        $userString = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($user['string'] ?? ''))) ?? '';
        return $userString !== '' ? $userString : null;
    }

    /**
     * Builds panel-visible routing inventory rows for feed/page/channel/category/tag/redirect/user/group.
     *
     * @return array<int, array{
     *   type_key: string,
     *   type_label: string,
     *   source_label: string,
     *   edit_url: string,
     *   public_url: string,
     *   target_url: string,
     *   status_key: string,
     *   status_label: string,
     *   notes: string,
     *   is_conflict: bool
     * }>
     */
    private function routingRowsForPanel(): array
    {
        $categoryPrefix = $this->categoryRoutePrefix();
        $tagPrefix = $this->tagRoutePrefix();
        $profilePrefix = $this->profileRoutePrefix();
        $profileRoutesEnabled = $this->profileRoutesEnabledForRoutingTable();
        $groupPrefix = $this->groupRoutePrefix();
        $groupRoutesEnabled = $this->groupRoutesEnabledForRoutingTable();

        $groupRoutingEnabled = $groupRoutesEnabled && $groupPrefix !== '';
        $userRoutingEnabled = $profileRoutesEnabled && $profilePrefix !== '';
        $routingAuthData = $this->userRepo->listRoutingData($groupRoutingEnabled, $userRoutingEnabled);
        $routingGroups = is_array($routingAuthData['group_rows'] ?? null) ? $routingAuthData['group_rows'] : [];
        $routingUsers = is_array($routingAuthData['user_rows'] ?? null) ? $routingAuthData['user_rows'] : [];
        $taxonomyRoutingOptionSets = $this->routingInventoryTaxonomyOptionSets($categoryPrefix, $tagPrefix);

        return $this->routingInventoryBuilder()->buildRows([
            'reserved_prefixes' => $this->reservedPublicPrefixes(),
            'channel_index_template_exists' => $this->channelIndexTemplateExistsForRouting(),
            'feed_enabled' => $this->routeConfigService()->feedEnabled(),
            'rss_feed_route' => $this->routeConfigService()->rssFeedRoute(),
            'atom_feed_route' => $this->routeConfigService()->atomFeedRoute(),
            'category_prefix' => $categoryPrefix,
            'tag_prefix' => $tagPrefix,
            'profile_prefix' => $profilePrefix,
            'profile_routes_enabled' => $profileRoutesEnabled,
            'group_prefix' => $groupPrefix,
            'group_routes_enabled' => $groupRoutesEnabled,
            'can_edit_configuration' => $this->context->auth()->canManageConfiguration(),
            'can_edit_pages' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::PAGES_EDIT),
            'can_edit_channels' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::CHANNELS_EDIT),
            'can_edit_categories' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::CATEGORIES_EDIT),
            'can_edit_tags' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::TAGS_EDIT),
            'can_edit_redirects' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::REDIRECTS_EDIT),
            'can_edit_users' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::USERS_EDIT),
            'can_edit_groups' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::GROUPS_EDIT),
            'routing_groups' => $routingGroups,
            'routing_users' => $routingUsers,
            'channel_routing_options' => is_array($taxonomyRoutingOptionSets['channel_options'] ?? null)
                ? $taxonomyRoutingOptionSets['channel_options']
                : [],
            'category_routing_options' => is_array($taxonomyRoutingOptionSets['category_options_all'] ?? null)
                ? $taxonomyRoutingOptionSets['category_options_all']
                : [],
            'tag_routing_options' => is_array($taxonomyRoutingOptionSets['tag_options_all'] ?? null)
                ? $taxonomyRoutingOptionSets['tag_options_all']
                : [],
            'redirect_routing_rows' => is_array($taxonomyRoutingOptionSets['redirect_rows'] ?? null)
                ? $taxonomyRoutingOptionSets['redirect_rows']
                : [],
            'pages_for_routing' => $this->pageRepo->listAllForRouting(),
            'build_page_url' => fn (
                string $pageSlug,
                int $pageId,
                string $channelSlug,
                string $publishedAt,
                string $channelPageRouteMode,
                string $channelPageUrlSeparator
            ): string => $this->routingPublicPathForPage(
                $pageSlug,
                $pageId,
                $channelSlug,
                $publishedAt,
                $channelPageRouteMode,
                $channelPageUrlSeparator
            ),
            'channel_landing_map_builder' => fn (array $pagesForRouting): array => $this->channelLandingMapFromPagesForRouting($pagesForRouting),
            'panel_url' => fn (string $suffix): string => $this->context->panelUrl($suffix),
            'build_user_route_segment' => fn (array $user): ?string => $this->publicProfileRouteSegmentForUser($user),
            'slugify_group_name' => fn (string $name): string => $this->slugifyGroupName($name),
        ]);
    }

    /**
     * Builds one routing-table public URL path for a page row.
     */
    private function routingPublicPathForPage(
        string $pageSlug,
        int $pageId,
        string $channelSlug,
        string $publishedAt,
        string $routeModeEffective,
        string $routeSeparatorEffective
    ): string {
        return $this->panelRoutingPreviewService()->routingPublicPathForPage(
            $pageSlug,
            $pageId,
            $channelSlug,
            $publishedAt,
            $channelSlug === ''
                ? $this->globalPageRouteMode()
                : $this->effectiveChannelRouteMode($routeModeEffective),
            $routeSeparatorEffective,
            (string) $this->config->get('content.separator', '-')
        );
    }

    /**
     * Derives one channel -> landing page slug map from routing page rows.
     *
     * @param array<int, array<string, mixed>> $pagesForRouting Page rows used for routing inventory.
     * @return array<string, string> Channel slug to landing page slug map.
     */
    private function channelLandingMapFromPagesForRouting(array $pagesForRouting): array
    {
        return $this->panelRoutingPreviewService()->channelLandingMapFromPages($pagesForRouting);
    }

    /**
     * Returns true when the public channel index template resolves in the active theme chain or core fallback.
     */
    private function channelIndexTemplateExistsForRouting(): bool
    {
        return $this->panelRoutingPreviewService()->channelIndexTemplateExists($this->config);
    }

    /**
     * Returns reserved root/channel slugs blocked by public router prefixes.
     *
     * @return array<int, string> Reserved public prefixes.
     */
    private function reservedPublicPrefixes(): array
    {
        return $this->panelRoutingPreviewService()->reservedPublicPrefixes(
            (string) $this->config->get('panel.path', 'panel'),
            [
                $this->categoryRoutePrefix(),
                $this->tagRoutePrefix(),
                $this->profileRoutePrefix(),
                $this->groupRoutePrefix(),
            ]
        );
    }

    /**
     * Returns the default profile-contact option map.
     *
     * @return array<string, array{label: string, prefix: string}> Default option map.
     */
    private function defaultProfileContactOptions(): array
    {
        return $this->profileContactService()->defaultOptions();
    }

    /**
     * Normalizes submitted profile-contact option rows from the configuration editor.
     *
     * @param mixed $rawOptions Submitted option payload.
     * @return array<string, array{label: string, prefix: string}> Normalized option map.
     */
    private function normalizeSubmittedProfileContactOptionsConfig(mixed $rawOptions): array
    {
        return $this->profileContactService()->normalizeSubmittedOptions($rawOptions);
    }

    /**
     * Returns one normalized config-editor tab key.
     */
    private function normalizeConfigEditorTab(mixed $value): string
    {
        return $this->panelEditorTabService()->normalizeConfigEditorTab($value);
    }

    /**
     * Builds the configuration URL while preserving the selected tab.
     */
    private function configurationUrlForTab(string $tab): string
    {
        return $this->panelEditorTabService()->configurationUrlForTab(
            fn (string $suffix): string => $this->context->panelUrl($suffix),
            $tab
        );
    }

    /**
     * Returns public theme rows for the theme-manager list view.
     *
     * @return array<int, array{
     *   slug: string,
     *   name: string,
     *   is_stock: bool,
     *   is_child_theme: bool,
     *   parent_theme: string,
     *   has_css: bool,
     *   has_wrapper: bool,
     *   inheritance_chain: string
     * }>
     */
    private function listPublicThemesForPanel(): array
    {
        return $this->themeCatalogService()->listForPanel();
    }

    /**
     * Validates public-theme slugs for safe filesystem usage.
     */
    private function isSafePublicThemeSlug(string $slug): bool
    {
        return $this->themeCatalogService()->isSafeSlug($slug);
    }

    /**
     * Derives one public-theme slug from an archive filename.
     */
    private function themeSlugFromArchiveFilename(string $archiveName): ?string
    {
        return $this->themeCatalogService()->slugFromArchiveFilename($archiveName);
    }

    /**
     * Resolves the next available public-theme slug by appending copy suffixes.
     */
    private function nextAvailablePublicThemeSlug(string $baseSlug): ?string
    {
        return $this->themeCatalogService()->nextAvailableSlug($baseSlug);
    }

    /**
     * Resolves the next available extension directory name by appending copy suffixes.
     */
    private function nextAvailableExtensionDirectoryName(string $baseName): ?string
    {
        $normalizedBase = strtolower(trim($baseName));
        if (!$this->isSafeExtensionDirectoryName($normalizedBase)) {
            return null;
        }

        $extensionsRoot = $this->extensionsBasePath();
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
            if (!$this->isSafeExtensionDirectoryName($candidate)) {
                continue;
            }

            if (!file_exists($extensionsRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns canonical stock public theme slugs protected from deletion.
     *
     * @return array<int, string> Stock public theme slugs.
     */
    private function stockPublicThemeSlugs(): array
    {
        return $this->themeCatalogService()->stockSlugs();
    }

    /**
     * Returns true when one public theme slug is part of the stock bundle.
     */
    private function isStockPublicThemeSlug(string $slug): bool
    {
        return $this->themeCatalogService()->isStockSlug($slug);
    }

    /**
     * Creates one minimal public-theme scaffold.
     *
     * @param string $themePath Target theme directory path.
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $meta Scaffold metadata.
     * @param bool $generateAgentsFile Whether to generate a theme-local AGENTS file.
     * @param bool $generateComposerFile Whether to generate a composer.json file.
     * @param bool $generatePackageFile Whether to generate a package.json file.
     * @return void
     */
    private function createPublicThemeSkeleton(
        string $themePath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false,
        bool $generatePackageFile = false
    ): void {
        $this->themeScaffoldService()->createSkeleton(
            $themePath,
            $meta,
            $generateAgentsFile,
            $generateComposerFile,
            $generatePackageFile
        );
    }

    /**
     * Returns generated theme-local AGENTS guidance content.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   is_child_theme?: bool,
     *   parent_theme?: string
     * } $meta Theme metadata.
     * @return string Generated AGENTS file content.
     */
    private function publicThemeAgentsFileContent(array $meta): string
    {
        return $this->themeScaffoldService()->agentsFileContent($meta);
    }

    /**
     * Returns generated composer.json content for one public-theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta Theme metadata.
     * @return string Generated composer.json content.
     */
    private function publicThemeComposerFileContent(array $meta): string
    {
        return $this->themeScaffoldService()->composerFileContent($meta);
    }

    /**
     * Returns generated package.json content for one public-theme scaffold.
     *
     * @param array{
     *   slug: string,
     *   name: string
     * } $meta Theme metadata.
     * @return string Generated package.json content.
     */
    private function publicThemePackageFileContent(array $meta): string
    {
        return $this->themeScaffoldService()->packageFileContent($meta);
    }

    /**
     * Writes theme.json with normalized manifest payload.
     *
     * @param string $manifestPath Theme manifest path.
     * @param array{
     *   name: string,
     *   is_child_theme: bool,
     *   parent_theme: string
     * } $manifest Theme manifest payload.
     * @return void
     */
    private function writePublicThemeManifest(string $manifestPath, array $manifest): void
    {
        $payload = [
            'name' => (string) $manifest['name'],
            'is_child_theme' => (bool) $manifest['is_child_theme'],
            'parent_theme' => (bool) $manifest['is_child_theme'] ? (string) $manifest['parent_theme'] : '',
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to build theme manifest JSON.');
        }

        $this->writePublicThemeScaffoldFile($manifestPath, $encoded . "\n");
    }

    /**
     * Writes one scaffold file for a public theme.
     *
     * @param string $targetPath Target file path.
     * @param string $content File contents to write.
     * @return void
     */
    private function writePublicThemeScaffoldFile(string $targetPath, string $content): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create directory: ' . $directory);
        }

        $written = file_put_contents($targetPath, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Failed to write file: ' . $targetPath);
        }

        @chmod($targetPath, 0644);
    }

    /**
     * Copies one directory tree recursively for local scaffold cloning.
     *
     * @param string $sourceDirectory Source directory path.
     * @param string $targetDirectory Target directory path.
     * @return void
     */
    private function copyDirectoryRecursively(string $sourceDirectory, string $targetDirectory): void
    {
        $this->themeCloneService()->copyDirectoryRecursively($sourceDirectory, $targetDirectory);
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string> Theme slug to display name map.
     */
    private function publicThemeOptions(): array
    {
        return $this->themeCatalogService()->options();
    }

    /**
     * Returns the filesystem root containing public themes.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService()->root();
    }

    /**
     * Resolves the active public theme slug from configuration plus discovered manifests.
     */
    private function activePublicThemeSlug(): string
    {
        return $this->themeCatalogService()->activeSlugFromConfig($this->config);
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
     * Discovers installed extensions from `private/ext/{name}/`.
     *
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
    private function listExtensionsForPanel(): array
    {
        return $this->extensionCatalogService()->listForPanel(
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
    }

    /**
     * Reads optional extension metadata from ext.json.
     *
     * @param string $extensionPath Extension directory path.
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
    private function readExtensionManifest(string $extensionPath): array
    {
        return $this->extensionCatalogService()->readManifest(
            $extensionPath,
            fn (string $extensionKey): array => $this->listEnabledExtensionForms($extensionKey)
        );
    }

    /**
     * Returns the absolute path to `private/ext`.
     */
    private function extensionsBasePath(): string
    {
        return $this->extensionStateStore()->basePath();
    }

    /**
     * Returns the extension storage provisioner on first use.
     */
    private function extensionStorageProvisioner(): ExtensionStorageProvisioner
    {
        if (!$this->extensionStorageProvisioner instanceof ExtensionStorageProvisioner) {
            $this->extensionStorageProvisioner = new ExtensionStorageProvisioner($this->root);
        }

        return $this->extensionStorageProvisioner;
    }

    /**
     * Returns the extension bootstrap-contract resolver on first use.
     */
    private function extensionBootstrapContractResolver(): ExtensionBootstrapContractResolver
    {
        if (!$this->extensionBootstrapContractResolver instanceof ExtensionBootstrapContractResolver) {
            $this->extensionBootstrapContractResolver = new ExtensionBootstrapContractResolver();
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
        $connectionFactory = new ConnectionFactory($databaseConfig);
        $cleaner = new ExtensionStorageCleaner(
            $this->root,
            $connectionFactory->createAppConnection(),
            $connectionFactory->getDriver(),
            $connectionFactory->getPrefix()
        );

        $cleaner->deleteStorageByContract($extensionName, (array) ($contract['storage'] ?? []));
    }

    /**
     * Ensures the extension base directory exists.
     *
     * @return void
     */
    private function ensureExtensionsDirectory(): void
    {
        $this->extensionStateStore()->ensureDirectory();
    }

    /**
     * Loads the enabled extension map from disk.
     *
     * @return array<string, bool> Enabled extension map.
     */
    private function loadExtensionStateMap(): array
    {
        return $this->extensionStateStore()->loadEnabledMap();
    }

    /**
     * Loads the required panel-side permission bit map per extension.
     *
     * @return array<string, int> Required permission-bit map.
     */
    private function loadExtensionPermissionMap(): array
    {
        return $this->extensionStateStore()->loadPermissionMap();
    }

    /**
     * Loads the extension permission-bit map per extension level.
     *
     * @return array<string, array<string, int>> Extension permission-bit map.
     */
    private function loadExtensionPermissionBitsMap(): array
    {
        return $this->extensionStateStore()->loadPermissionBitsMap();
    }

    /**
     * Saves the enabled extension map.
     *
     * @param array<string, bool> $enabledMap Enabled extension map.
     * @return void
     */
    private function saveExtensionStateMap(array $enabledMap): void
    {
        $this->extensionStateStore()->saveEnabledMap($enabledMap);
    }

    /**
     * Saves extension enablement plus permission state.
     *
     * @param array<string, bool> $enabledMap Enabled extension map.
     * @param array<string, int> $permissionMap Required permission-bit map.
     * @param array<string, array<string, int>> $permissionBitsMap Level-to-bit map.
     * @return void
     */
    private function saveExtensionState(array $enabledMap, array $permissionMap, array $permissionBitsMap = []): void
    {
        $this->extensionStateStore()->saveState($enabledMap, $permissionMap, $permissionBitsMap);
    }

    /**
     * Returns canonical stock extension directory names protected from deletion.
     *
     * @return array<int, string> Stock extension directory names.
     */
    private function stockExtensionDirectories(): array
    {
        return $this->extensionCatalogService()->stockExtensionDirectories();
    }

    /**
     * Returns true when one extension directory is part of the stock bundle.
     */
    private function isStockExtensionDirectory(string $directoryName): bool
    {
        return $this->extensionCatalogService()->isStockExtensionDirectory($directoryName);
    }

    /**
     * Validates extension directory names for filesystem-safe usage.
     */
    private function isSafeExtensionDirectoryName(string $name): bool
    {
        return $this->extensionCatalogService()->isSafeExtensionDirectoryName($name);
    }

    /**
     * Creates a minimal extension scaffold on disk.
     *
     * @param string $extensionPath Target extension directory path.
     * @param array{
     *   directory: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   type: string,
     *   author: string,
     *   homepage: string,
     *   docs: string
     * } $meta Extension scaffold metadata.
     * @param bool $generateAgentsFile Whether to generate a local AGENTS file.
     * @param bool $generateComposerFile Whether to generate a composer.json file.
     * @return void
     */
    private function createExtensionSkeleton(
        string $extensionPath,
        array $meta,
        bool $generateAgentsFile = false,
        bool $generateComposerFile = false
    ): void {
        $this->extensionScaffoldService()->createSkeleton(
            $extensionPath,
            $meta,
            $generateAgentsFile,
            $generateComposerFile
        );
    }

    /**
     * Returns the configured public category route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        return $this->routeConfigService()->categoryRoutePrefix();
    }

    /**
     * Returns the configured public tag route prefix.
     */
    private function tagRoutePrefix(): string
    {
        return $this->routeConfigService()->tagRoutePrefix();
    }

    /**
     * Returns the configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->routeConfigService()->profileRoutePrefix();
    }

    /**
     * Returns true when public profile URLs are enabled for routing inventory.
     */
    private function profileRoutesEnabledForRoutingTable(): bool
    {
        return $this->routeConfigService()->profileRoutesEnabledForRoutingTable();
    }

    /**
     * Returns the configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->routeConfigService()->groupRoutePrefix();
    }

    /**
     * Returns true when public group URLs are enabled for routing inventory.
     */
    private function groupRoutesEnabledForRoutingTable(): bool
    {
        return $this->routeConfigService()->groupRoutesEnabledForRoutingTable();
    }

    /**
     * Derives one stable URL slug from a group name.
     */
    private function slugifyGroupName(string $groupName): string
    {
        $slug = $this->input->slug($groupName);
        if ($slug === null || $slug === '') {
            return '';
        }

        return $slug;
    }

    /**
     * Normalizes panel-theme identifiers to valid theme slugs.
     */
    private function normalizePanelThemeChoice(string $theme, bool $allowDefault): ?string
    {
        $normalized = strtolower(trim($theme));
        if ($normalized === '') {
            return $allowDefault ? 'default' : 'corp';
        }

        if ($allowDefault && $normalized === 'default') {
            return 'default';
        }

        if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
            return $normalized;
        }

        return null;
    }

    /**
     * Returns the archive-package service on first use.
     */
    private function archivePackages(): ArchivePackageService
    {
        if (!$this->archivePackages instanceof ArchivePackageService) {
            $this->archivePackages = new ArchivePackageService($this->root);
        }

        return $this->archivePackages;
    }

    /**
     * Returns the extension-state store on first use.
     */
    private function extensionStateStore(): ExtensionStateStore
    {
        if (!$this->extensionStateStore instanceof ExtensionStateStore) {
            $this->extensionStateStore = new ExtensionStateStore($this->root . '/private/ext');
        }

        return $this->extensionStateStore;
    }

    /**
     * Returns the extension-scaffold service on first use.
     */
    private function extensionScaffoldService(): ExtensionScaffoldService
    {
        if (!$this->extensionScaffoldService instanceof ExtensionScaffoldService) {
            $this->extensionScaffoldService = new ExtensionScaffoldService();
        }

        return $this->extensionScaffoldService;
    }

    /**
     * Returns the public-theme scaffold service on first use.
     */
    private function themeScaffoldService(): ThemeScaffoldService
    {
        if (!$this->themeScaffoldService instanceof ThemeScaffoldService) {
            $this->themeScaffoldService = new ThemeScaffoldService();
        }

        return $this->themeScaffoldService;
    }

    /**
     * Returns the config-editor normalizer on first use.
     */
    private function configEditorNormalizer(): ConfigEditorNormalizer
    {
        if (!$this->configEditorNormalizer instanceof ConfigEditorNormalizer) {
            $this->configEditorNormalizer = new ConfigEditorNormalizer();
        }

        return $this->configEditorNormalizer;
    }

    /**
     * Returns the config-editor defaults service on first use.
     */
    private function panelConfigDefaultsService(): PanelConfigDefaultsService
    {
        if (!$this->panelConfigDefaultsService instanceof PanelConfigDefaultsService) {
            $this->panelConfigDefaultsService = new PanelConfigDefaultsService(
                $this->configEditorSchemaService(),
                $this->configEditorNormalizer()
            );
        }

        return $this->panelConfigDefaultsService;
    }

    /**
     * Returns the config-field policy service on first use.
     */
    private function panelConfigFieldPolicyService(): PanelConfigFieldPolicyService
    {
        if (!$this->panelConfigFieldPolicyService instanceof PanelConfigFieldPolicyService) {
            $this->panelConfigFieldPolicyService = new PanelConfigFieldPolicyService(
                $this->config,
                $this->input,
                $this->panelConfigDefaultsService(),
                $this->configEditorNormalizer()
            );
        }

        return $this->panelConfigFieldPolicyService;
    }

    /**
     * Returns the package-install workflow service on first use.
     */
    private function packageInstallWorkflowService(): PackageInstallWorkflowService
    {
        if (!$this->packageInstallWorkflowService instanceof PackageInstallWorkflowService) {
            $this->packageInstallWorkflowService = new PackageInstallWorkflowService(
                $this->input,
                $this->archivePackages()
            );
        }

        return $this->packageInstallWorkflowService;
    }

    /**
     * Returns the directory-tree helper on first use.
     */
    private function directoryTreeService(): DirectoryTreeService
    {
        if (!$this->directoryTreeService instanceof DirectoryTreeService) {
            $this->directoryTreeService = new DirectoryTreeService();
        }

        return $this->directoryTreeService;
    }

    /**
     * Returns the profile-contact service on first use.
     */
    private function profileContactService(): ProfileContactService
    {
        if (!$this->profileContactService instanceof ProfileContactService) {
            $this->profileContactService = new ProfileContactService($this->input);
        }

        return $this->profileContactService;
    }

    /**
     * Returns the route-config service on first use.
     */
    private function routeConfigService(): RouteConfigService
    {
        if (!$this->routeConfigService instanceof RouteConfigService) {
            $this->routeConfigService = new RouteConfigService($this->config, $this->input);
        }

        return $this->routeConfigService;
    }

    /**
     * Returns the panel editor-tab service on first use.
     */
    private function panelEditorTabService(): PanelEditorTabService
    {
        if (!$this->panelEditorTabService instanceof PanelEditorTabService) {
            $this->panelEditorTabService = new PanelEditorTabService($this->input);
        }

        return $this->panelEditorTabService;
    }

    /**
     * Returns the panel routing-preview service on first use.
     */
    private function panelRoutingPreviewService(): PanelRoutingPreviewService
    {
        if (!$this->panelRoutingPreviewService instanceof PanelRoutingPreviewService) {
            $this->panelRoutingPreviewService = new PanelRoutingPreviewService(
                $this->root,
                $this->input,
                $this->themeCatalogService()
            );
        }

        return $this->panelRoutingPreviewService;
    }

    /**
     * Returns the config-editor schema service on first use.
     */
    private function configEditorSchemaService(): ConfigEditorSchemaService
    {
        if (!$this->configEditorSchemaService instanceof ConfigEditorSchemaService) {
            $this->configEditorSchemaService = new ConfigEditorSchemaService(
                $this->input,
                $this->profileContactService()
            );
        }

        return $this->configEditorSchemaService;
    }

    /**
     * Returns the routing-inventory builder on first use.
     */
    private function routingInventoryBuilder(): RoutingInventoryBuilder
    {
        if (!$this->routingInventoryBuilder instanceof RoutingInventoryBuilder) {
            $this->routingInventoryBuilder = new RoutingInventoryBuilder($this->input);
        }

        return $this->routingInventoryBuilder;
    }

    /**
     * Returns the extension-permission catalog service on first use.
     */
    private function extensionPermissionCatalogService(): ExtensionPermissionCatalogService
    {
        if (!$this->extensionPermissionCatalogService instanceof ExtensionPermissionCatalogService) {
            $this->extensionPermissionCatalogService = new ExtensionPermissionCatalogService(
                $this->extensionStateStore(),
                $this->input
            );
        }

        return $this->extensionPermissionCatalogService;
    }

    /**
     * Returns the extension catalog service on first use.
     */
    private function extensionCatalogService(): ExtensionCatalogService
    {
        if (!$this->extensionCatalogService instanceof ExtensionCatalogService) {
            $this->extensionCatalogService = new ExtensionCatalogService(
                $this->root,
                $this->extensionStateStore(),
                $this->extensionPermissionCatalogService(),
                $this->config,
                $this->input
            );
        }

        return $this->extensionCatalogService;
    }

    /**
     * Returns the public-theme catalog service on first use.
     */
    private function themeCatalogService(): ThemeCatalogService
    {
        if (!$this->themeCatalogService instanceof ThemeCatalogService) {
            $this->themeCatalogService = new ThemeCatalogService(
                $this->root . '/public/theme',
                $this->input,
                ['raven']
            );
        }

        return $this->themeCatalogService;
    }

    /**
     * Returns the git-command runner on first use.
     */
    private function gitCommandRunner(): GitCommandRunner
    {
        if (!$this->gitCommandRunner instanceof GitCommandRunner) {
            $this->gitCommandRunner = new GitCommandRunner();
        }

        return $this->gitCommandRunner;
    }

    /**
     * Returns the update-source resolver on first use.
     */
    private function updateSourceResolver(): UpdateSourceResolver
    {
        if (!$this->updateSourceResolver instanceof UpdateSourceResolver) {
            $this->updateSourceResolver = new UpdateSourceResolver($this->input);
        }

        return $this->updateSourceResolver;
    }

    /**
     * Returns the update workflow service on first use.
     */
    private function updateWorkflowService(): UpdateWorkflowService
    {
        if (!$this->updateWorkflowService instanceof UpdateWorkflowService) {
            $this->updateWorkflowService = new UpdateWorkflowService(
                $this->root,
                $this->gitCommandRunner(),
                $this->stockPublicThemeSlugs(),
                $this->stockExtensionDirectories()
            );
        }

        return $this->updateWorkflowService;
    }

    /**
     * Returns the config-snapshot sanitizer on first use.
     */
    private function configSnapshotSanitizer(): ConfigSnapshotSanitizer
    {
        if (!$this->configSnapshotSanitizer instanceof ConfigSnapshotSanitizer) {
            $this->configSnapshotSanitizer = new ConfigSnapshotSanitizer();
        }

        return $this->configSnapshotSanitizer;
    }

    /**
     * Returns the theme-clone service on first use.
     */
    private function themeCloneService(): ThemeCloneService
    {
        if (!$this->themeCloneService instanceof ThemeCloneService) {
            $this->themeCloneService = new ThemeCloneService();
        }

        return $this->themeCloneService;
    }

    /**
     * Returns the public fallback renderer on first use.
     */
    private function publicFallbackRenderer(): ThemeFallbackRenderer
    {
        if (!$this->publicFallbackRenderer instanceof ThemeFallbackRenderer) {
            $this->publicFallbackRenderer = new ThemeFallbackRenderer(
                $this->publicThemesRoot(),
                $this->root . '/private/tpl',
                $this->root . '/.tmp/template_tag_cache'
            );
        }

        return $this->publicFallbackRenderer;
    }

    /**
     * Returns the site-context builder on first use.
     */
    private function siteContextBuilder(): SiteContextBuilder
    {
        if (!$this->siteContextBuilder instanceof SiteContextBuilder) {
            $this->siteContextBuilder = new SiteContextBuilder();
        }

        return $this->siteContextBuilder;
    }

    /**
     * Returns site context passed to public fallback templates.
     *
     * @return array<string, string> Public fallback site payload.
     */
    private function publicSiteDataForNotFound(): array
    {
        $publicTheme = $this->activePublicThemeSlug();
        return $this->siteContextBuilder()->publicFallback(
            $this->config,
            $publicTheme,
            $this->themeCatalogService()->cssSlug($publicTheme)
        );
    }

    /**
     * Renders the updater page with the standard panel wrapper.
     *
     * @param array<string, mixed> $source Resolved update source settings.
     * @param array<string, mixed> $result Update comparison or execution result.
     * @param string|null $flashSuccess Success message to display.
     * @param string|null $flashError Error message to display.
     * @param bool $allowOverwrite Whether local overwrite is currently allowed.
     * @return void
     */
    private function renderUpdatePage(
        array $source,
        array $result,
        ?string $flashSuccess,
        ?string $flashError,
        bool $allowOverwrite
    ): void {
        $this->context->renderPanel('panel/update', [
            'canManageConfiguration' => $this->context->auth()->canManageConfiguration(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $flashSuccess,
            'flashError' => $flashError,
            'section' => 'update',
            'pageTitle' => 'Update Raven',
            'updateSource' => $source,
            'updateResult' => $result,
            'allowOverwrite' => $allowOverwrite,
            'updateSourceModes' => [
                'github_mirror' => 'Github Mirror (noveltylanterns/raven)',
                'github_custom' => 'Custom Github',
                'repo_custom' => 'Custom Repo',
            ],
        ]);
    }

    /**
     * Persists the selected updater source config.
     *
     * @param array<string, mixed> $source Resolved update source settings.
     * @return void
     */
    private function persistUpdateSourceConfig(array $source): void
    {
        $nextConfig = $this->config->all();
        $nextConfig['update'] = is_array($nextConfig['update'] ?? null) ? $nextConfig['update'] : [];
        $nextConfig['update']['source'] = [
            'mode' => (string) ($source['mode'] ?? 'github_mirror'),
            'github_repo' => (string) ($source['github_repo'] ?? 'noveltylanterns/raven'),
            'repo_url' => (string) ($source['repo_url'] ?? ''),
        ];

        $this->config->replace($nextConfig);
        $this->config->save();
    }
}
