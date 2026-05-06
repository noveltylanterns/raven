<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ThemeController.php
 * Split panel theme-manager controller that owns `/themes*` routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Config;
use Raven\Lib\Archive\Folder as ArchiveDelete;
use Raven\Lib\Archive\Install as ArchiveInstall;
use Raven\Lib\Archive\Package as ArchivePackage;
use Raven\Lib\Scribe\ConfigScribe;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Upload;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeDiscovery;
use Raven\Lib\View\Public\ThemeGenerator;

/**
 * Handles panel theme-manager routes.
 */
final class ThemeController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private string $root;
    private ThemeCatalog $themeCatalogService;
    private ?ArchivePackage $archivePackages = null;
    private ?ThemeGenerator $themeGenerator = null;
    private ?ArchiveInstall $packageInstallWorkflowService = null;
    private ?ArchiveDelete $directoryTreeService = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for filesystem-backed admin workflows.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for theme inventory and slug validation.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        ThemeCatalog $themeCatalogService
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->themeCatalogService = $themeCatalogService;
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

        $archivePackages = $this->archivePackages();

        $this->context->renderPanel('panel/themes', [
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'themes',
            'themes' => $this->themeCatalogService->listForPanel(),
            'activeTheme' => $this->themeCatalogService->activeSlugFromConfig($this->config),
            'themeOptions' => ThemeDiscovery::options($this->themeCatalogService->root()),
            'packageArchiveAcceptAttribute' => $archivePackages->accept(),
            'packageArchiveFormats' => $archivePackages->formatLabels(),
            'exportArchiveFormats' => $archivePackages->exportFormatOptions(),
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
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['theme'] ?? null, 80)));
        if (!$this->themeCatalogService->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $availableThemes = $this->themeCatalogService->options();
        if (!isset($availableThemes[$themeSlug])) {
            $this->context->flash('error', 'Theme "' . $themeSlug . '" is not available.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        try {
            ConfigScribe::persistValue($this->config->path(), $this->config->all(), 'site.theme', $themeSlug);
            $this->config = new Config($this->config->path());
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Failed to update active theme: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $this->context->flash('success', 'Active public theme set to "' . ($availableThemes[$themeSlug] ?? $themeSlug) . '".');
        Redirect::redirect($this->context->panelUrl('/themes'));
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
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeName = trim((string) $this->input->text($post['name'] ?? null, 120));
        if ($themeName === '') {
            $this->context->flash('error', 'Theme name is required.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['slug'] ?? null, 80)));
        if (!$this->themeCatalogService->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Theme slug must use lowercase letters, numbers, underscores, or dashes.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $parentTheme = strtolower(trim((string) $this->input->text($post['parent_theme'] ?? null, 80)));
        if ($parentTheme !== '' && !$this->themeCatalogService->isSafeSlug($parentTheme)) {
            $this->context->flash('error', 'Parent theme slug is invalid.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $cloneTheme = strtolower(trim((string) $this->input->text($post['clone_theme'] ?? null, 80)));
        if ($cloneTheme !== '' && !$this->themeCatalogService->isSafeSlug($cloneTheme)) {
            $this->context->flash('error', 'Clone-source theme slug is invalid.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themesRoot = $this->themeCatalogService->root();
        $themeOptions = ThemeDiscovery::options($themesRoot);
        $themeManifests = ThemeDiscovery::manifests($themesRoot);
        if ($parentTheme !== '' && !isset($themeOptions[$parentTheme])) {
            $this->context->flash('error', 'Selected parent theme was not found.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }
        if ($cloneTheme !== '' && !isset($themeOptions[$cloneTheme])) {
            $this->context->flash('error', 'Selected clone-source theme was not found.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }
        if ($parentTheme === $themeSlug) {
            $this->context->flash('error', 'A child theme cannot use itself as parent.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';
        $generatePackageFile = isset($post['generate_package']) && (string) $post['generate_package'] === '1';
        $setActive = isset($post['set_active']) && (string) $post['set_active'] === '1';
        $themePath = $themesRoot . '/' . $themeSlug;
        if (file_exists($themePath)) {
            $this->context->flash('error', 'A theme directory with this slug already exists.');
            Redirect::redirect($this->context->panelUrl('/themes'));
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
                $this->themeGenerator()->copyDirectoryRecursively($clonePath, $themePath);
                $this->themeGenerator()->finalizeClone(
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
            } else {
                $this->themeGenerator()->createSkeleton(
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
            $this->directoryTreeService()->removeTree($themePath);
            $this->context->flash('error', 'Failed to create theme scaffold: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        if ($setActive) {
            try {
                ConfigScribe::persistValue($this->config->path(), $this->config->all(), 'site.theme', $themeSlug);
                $this->config = new Config($this->config->path());
            } catch (\RuntimeException $exception) {
                $this->directoryTreeService()->removeTree($themePath);
                $this->context->flash('error', 'Theme scaffold created, but activation failed: ' . $exception->getMessage());
                Redirect::redirect($this->context->panelUrl('/themes'));
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
                $generated[] = 'agents';
                $generated[] = 'AGENTS.md -> agents';
                $generated[] = 'CLAUDE.md -> agents';
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
        Redirect::redirect($this->context->panelUrl('/themes'));
    }

    /**
     * Uploads one public-theme archive package.
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
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $upload = $this->packageInstallWorkflowService()->validateUpload(
            $files['theme_archive'] ?? null,
            'Theme archive',
            'Themes'
        );
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->context->flash('error', (string) ($upload['error'] ?? 'Theme upload failed.'));
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'theme-package.zip');
        $derivedThemeSlug = $this->packageInstallWorkflowService()->themeSlug($tmpPath);

        $slugResult = $this->packageInstallWorkflowService()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedThemeSlug ?? $this->themeCatalogService->slugFromArchiveFilename($name),
            fn (string $slug): bool => $this->themeCatalogService->isSafeSlug($slug),
            fn (string $slug): bool => $this->themeCatalogService->isStockSlug($slug),
            fn (string $slug): ?string => $this->themeCatalogService->nextAvailableSlug($slug),
            fn (string $slug): bool => file_exists($this->themeCatalogService->root() . '/' . $slug),
            'Theme',
            'Theme slug must use lowercase letters, numbers, underscores, or dashes.'
        );
        if (!(bool) ($slugResult['ok'] ?? false)) {
            $slugError = (string) ($slugResult['error'] ?? 'Failed to resolve theme slug.');
            if (
                trim((string) ($post['upload_slug'] ?? '')) === ''
                && $derivedThemeSlug === null
                && $this->themeCatalogService->slugFromArchiveFilename($archiveName) === null
            ) {
                $slugError = 'Theme upload failed: theme.json must include a valid "slug" value or use Slug Override.';
            }

            $this->context->flash('error', $slugError);
            Redirect::redirect($this->context->panelUrl('/themes'));
        }
        $themeSlug = (string) ($slugResult['name'] ?? '');

        $themesRoot = $this->themeCatalogService->root();
        if (!is_dir($themesRoot) && !mkdir($themesRoot, 0775, true) && !is_dir($themesRoot)) {
            $this->context->flash('error', 'Failed to initialize public/theme directory.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $targetDirectory = $themesRoot . '/' . $themeSlug;
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->context->flash('error', 'Failed to create theme directory.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $extractError = $this->packageInstallWorkflowService()->extractTo(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTreeService()->removeTree($directory);
            },
            'theme'
        );
        if (is_string($extractError)) {
            $this->context->flash('error', $extractError);
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $flattenError = $this->packageInstallWorkflowService()->flattenRoot($targetDirectory);
        if (is_string($flattenError)) {
            $this->directoryTreeService()->removeTree($targetDirectory);
            $this->context->flash('error', $flattenError);
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $manifestPath = $targetDirectory . '/theme.json';
        if (!is_file($manifestPath)) {
            $this->directoryTreeService()->removeTree($targetDirectory);
            $this->context->flash('error', 'Theme upload failed: archive must include theme.json at archive root.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $manifests = ThemeDiscovery::manifests($themesRoot);
        if (!isset($manifests[$themeSlug])) {
            $this->directoryTreeService()->removeTree($targetDirectory);
            $this->context->flash('error', 'Theme upload failed: theme.json is missing required/valid metadata.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $message = 'Theme uploaded to public/theme/' . $themeSlug . '/. Enable it from the Installed Themes list when ready.';
        if ((bool) ($slugResult['renamed'] ?? false)) {
            $message .= ' Existing slug detected; upload was renamed automatically.';
        }

        $this->context->flash('success', $message);
        Redirect::redirect($this->context->panelUrl('/themes'));
    }

    /**
     * Exports an installed public theme as one downloadable archive.
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

        $themeSlug = strtolower(trim((string) $this->input->text($query['theme'] ?? null, 80)));
        if (!$this->themeCatalogService->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themePath = $this->themeCatalogService->root() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->context->flash('error', 'Theme directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $format = strtolower(trim((string) $this->input->text($query['format'] ?? 'zip', 20)));

        try {
            $archive = $this->archivePackages()->exportDir($themePath, $themeSlug, $format);
        } catch (\RuntimeException $exception) {
            $this->context->flash('error', 'Theme export failed: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $downloadFilename = $this->archivePackages()->downloadName('theme-' . $themeSlug, (string) ($archive['format'] ?? 'zip'));
        $this->archivePackages()->streamDownload(
            (string) ($archive['path'] ?? ''),
            $downloadFilename,
            (string) ($archive['mime_type'] ?? 'application/octet-stream')
        );
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
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['theme'] ?? null, 80)));
        if (!$this->themeCatalogService->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        if ($this->themeCatalogService->isStockSlug($themeSlug)) {
            $this->context->flash('error', 'Stock themes cannot be uninstalled.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themePath = $this->themeCatalogService->root() . '/' . $themeSlug;
        if (!is_dir($themePath)) {
            $this->context->flash('error', 'Theme directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        if ($this->themeCatalogService->activeSlugFromConfig($this->config) === $themeSlug) {
            $this->context->flash('error', 'Active theme cannot be uninstalled. Enable another theme first.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $this->directoryTreeService()->removeTree($themePath);
        if (is_dir($themePath)) {
            $this->context->flash('error', 'Failed to uninstall theme directory from disk.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $this->context->flash('success', 'Theme "' . $themeSlug . '" uninstalled.');
        Redirect::redirect($this->context->panelUrl('/themes'));
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
     * Returns the public-theme scaffold service on first use.
     */
    private function themeGenerator(): ThemeGenerator
    {
        if (!$this->themeGenerator instanceof ThemeGenerator) {
            $this->themeGenerator = new ThemeGenerator();
        }

        return $this->themeGenerator;
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
