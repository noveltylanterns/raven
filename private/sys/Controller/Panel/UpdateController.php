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
use Raven\Lib\Format\Git;
use Raven\Core\Repository\ConfigWrite;
use Raven\Lib\Security\InputSanitizer;

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
    /** @var array<int, string> */
    private array $stockPublicThemeSlugs;
    /** @var array<int, string> */
    private array $stockExtensionDirectories;
    private ?Git $git = null;
    private ?Upstream $upstream = null;
    private ?ArchiveUpdate $archiveUpdate = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for updater filesystem workflows.
     * @param array<int, string> $stockPublicThemeSlugs Canonical stock public theme slugs protected from overwrite.
     * @param array<int, string> $stockExtensionDirectories Canonical stock extension directories protected from overwrite.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        array $stockPublicThemeSlugs,
        array $stockExtensionDirectories
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->stockPublicThemeSlugs = $stockPublicThemeSlugs;
        $this->stockExtensionDirectories = $stockExtensionDirectories;
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

        $source = $this->upstream()->fromConfig($this->config->all());
        $result = $this->archiveUpdate()->compare($source);
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

        $source = $this->upstream()->fromPost(
            $post,
            $this->upstream()->fromConfig($this->config->all())
        );
        $allowOverwrite = ((string) ($post['allow_overwrite'] ?? '')) === '1';
        $error = null;
        $success = null;

        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $error = 'Invalid CSRF token.';
            $result = $this->archiveUpdate()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        $sourceErrors = $this->upstream()->validationErrors($source);
        if ($sourceErrors !== []) {
            $error = implode(' ', $sourceErrors);
            $result = $this->archiveUpdate()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        try {
            ConfigWrite::persistValue(
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
            $result = $this->archiveUpdate()->compare($source);
            $this->renderUpdatePage($source, $result, $success, $error, $allowOverwrite);
            return;
        }

        $action = strtolower(trim((string) ($post['update_action'] ?? 'check')));
        if (!in_array($action, ['check', 'dry_run', 'update_now'], true)) {
            $action = 'check';
        }

        $result = match ($action) {
            'dry_run' => $this->archiveUpdate()->dryRun($source, $allowOverwrite),
            'update_now' => $this->archiveUpdate()->update($source, $allowOverwrite),
            default => $this->archiveUpdate()->compare($source),
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
        return $this->stockPublicThemeSlugs;
    }

    /**
     * Returns canonical stock extension directory names protected from updater overwrite rules.
     *
     * @return array<int, string> Stock extension directory names.
     */
    private function stockExtensionDirectories(): array
    {
        return $this->stockExtensionDirectories;
    }

    /**
     * Returns the canonical Git handler on first use.
     */
    private function git(): Git
    {
        if (!$this->git instanceof Git) {
            $this->git = new Git();
        }

        return $this->git;
    }

    /**
     * Returns the update-source resolver on first use.
     *
     * @return Upstream Lazily initialized update source resolver.
     */
    private function upstream(): Upstream
    {
        if (!$this->upstream instanceof Upstream) {
            $this->upstream = new Upstream($this->input);
        }

        return $this->upstream;
    }

    /**
     * Returns the update workflow service on first use.
     *
     * @return ArchiveUpdate Lazily initialized update workflow service.
     */
    private function archiveUpdate(): ArchiveUpdate
    {
        if (!$this->archiveUpdate instanceof ArchiveUpdate) {
            $this->archiveUpdate = new ArchiveUpdate(
                $this->root,
                $this->git(),
                $this->stockPublicThemeSlugs(),
                $this->stockExtensionDirectories()
            );
        }

        return $this->archiveUpdate;
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
            'canManageConfiguration' => $this->context->auth()->panelService()->canManageConfiguration(),
            'csrfField' => $this->context->csrf()->field(),
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
