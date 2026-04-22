<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/UpdateController.php
 * Split panel updater controller for update routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Config;
use Raven\Lib\Archive\Update as ArchiveUpdate;
use Raven\Lib\Archive\Upstream;
use Raven\Lib\Extension\ExtensionStateStore;
use Raven\Lib\Extension\Panel\ExtensionCatalogService;
use Raven\Lib\Extension\Panel\ExtensionPermissionCatalogService;
use Raven\Lib\Format\Git;
use Raven\Lib\Scribe\ConfigScribe;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Public\ThemeCatalog;

/**
 * Handles the split panel updater routes.
 *
 * Owns the `/update*` route family so updater source selection, compare/dry-run
 * execution, and updater-page rendering no longer ride through the broader
 * system-management controller.
 */
final class UpdateController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private string $root;
    private ?ExtensionStateStore $extensionStateStore = null;
    private ?ExtensionPermissionCatalogService $extensionPermissionCatalogService = null;
    private ?ExtensionCatalogService $extensionCatalogService = null;
    private ?ThemeCatalog $themeCatalogService = null;
    private ?Git $gitArchiveHandler = null;
    private ?Upstream $updateSourceResolver = null;
    private ?ArchiveUpdate $updateWorkflowService = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for updater filesystem workflows.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
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
            ConfigScribe::persistValue(
                $this->config->path(),
                $this->config->all(),
                'update.source',
                [
                    'mode' => (string) ($source['mode'] ?? 'github_mirror'),
                    'github_repo' => (string) ($source['github_repo'] ?? 'noveltylanterns/raven'),
                    'repo_url' => (string) ($source['repo_url'] ?? ''),
                ]
            );
            $this->config = new Config($this->config->path());
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
     * Returns canonical stock public theme slugs protected from updater overwrite rules.
     *
     * @return array<int, string> Stock public theme slugs.
     */
    private function stockPublicThemeSlugs(): array
    {
        return $this->themeCatalogService()->stockSlugs();
    }

    /**
     * Returns canonical stock extension directory names protected from updater overwrite rules.
     *
     * @return array<int, string> Stock extension directory names.
     */
    private function stockExtensionDirectories(): array
    {
        return $this->extensionCatalogService()->stockExtensionDirectories();
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
    private function themeCatalogService(): ThemeCatalog
    {
        if (!$this->themeCatalogService instanceof ThemeCatalog) {
            $this->themeCatalogService = new ThemeCatalog(
                $this->root . '/public/theme',
                $this->input,
                ['raven']
            );
        }

        return $this->themeCatalogService;
    }

    /**
     * Returns the canonical Git handler on first use.
     */
    private function gitArchiveHandler(): Git
    {
        if (!$this->gitArchiveHandler instanceof Git) {
            $this->gitArchiveHandler = new Git();
        }

        return $this->gitArchiveHandler;
    }

    /**
     * Returns the update-source resolver on first use.
     *
     * @return Upstream Lazily initialized update source resolver.
     */
    private function updateSourceResolver(): Upstream
    {
        if (!$this->updateSourceResolver instanceof Upstream) {
            $this->updateSourceResolver = new Upstream($this->input);
        }

        return $this->updateSourceResolver;
    }

    /**
     * Returns the update workflow service on first use.
     *
     * @return ArchiveUpdate Lazily initialized update workflow service.
     */
    private function updateWorkflowService(): ArchiveUpdate
    {
        if (!$this->updateWorkflowService instanceof ArchiveUpdate) {
            $this->updateWorkflowService = new ArchiveUpdate(
                $this->root,
                $this->gitArchiveHandler(),
                $this->stockPublicThemeSlugs(),
                $this->stockExtensionDirectories()
            );
        }

        return $this->updateWorkflowService;
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
}
