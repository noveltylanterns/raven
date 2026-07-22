<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ThemeController.php
 * Split panel theme-manager controller that owns `/themes*` routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Config;
use Raven\Core\Repository\ConfigWrite;
use Raven\Lib\Archive\Folder as ArchiveDelete;
use Raven\Lib\Archive\Install as ArchiveInstall;
use Raven\Lib\Archive\Package as ArchivePackage;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Upload;
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
    private ThemeCatalog $themeCatalog;
    private ?ArchivePackage $archivePackages = null;
    private ?ThemeGenerator $themeGenerator = null;
    private ?ArchiveInstall $packageInstaller = null;
    private ?ArchiveDelete $directoryTree = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for filesystem-backed admin workflows.
     * @param ThemeCatalog $themeCatalog Shared public-theme catalog for theme inventory and slug validation.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        ThemeCatalog $themeCatalog
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->themeCatalog = $themeCatalog;
    }

    /**
     * Renders the public-theme manager.
     *
     * @return void
     */
    public function themes(): void
    {
        $this->context->requirePanelLogin();
        // Theme manager view is permission-gated.
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        $archivePackages = $this->archivePackages();

        $this->context->renderPanel('panel/themes', [
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'themes',
            'themes' => $this->themeCatalog->listForPanel(),
            'activeTheme' => $this->themeCatalog->activeSlugFromConfig($this->config),
            'themeOptions' => ThemeDiscovery::options($this->themeCatalog->root()),
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
        // Theme activation requires edit permission.
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'edit')) {
            return;
        }

        // CSRF validation protects theme activation changes.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['theme'] ?? null, 80)));
        // Theme slug must be filesystem-safe.
        if (!$this->themeCatalog->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $availableThemes = $this->themeCatalog->options();
        // Activation target must exist among discovered theme options.
        if (!isset($availableThemes[$themeSlug])) {
            $this->context->flash('error', 'Theme "' . $themeSlug . '" is not available.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        // Persisting config can fail due to IO/validation errors.
        try {
            ConfigWrite::persistValue($this->config->path(), $this->config->all(), 'site.theme', $themeSlug);
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
        // Theme scaffold creation requires create permission.
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        // CSRF validation protects theme scaffold generation.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeName = trim((string) $this->input->text($post['name'] ?? null, 120));
        // Human-readable theme name is required for manifest metadata.
        if ($themeName === '') {
            $this->context->flash('error', 'Theme name is required.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['slug'] ?? null, 80)));
        // New theme slug must be filesystem-safe.
        if (!$this->themeCatalog->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Theme slug must use lowercase letters, numbers, underscores, or dashes.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $parentTheme = strtolower(trim((string) $this->input->text($post['parent_theme'] ?? null, 80)));
        // Optional parent slug must also be filesystem-safe.
        if ($parentTheme !== '' && !$this->themeCatalog->isSafeSlug($parentTheme)) {
            $this->context->flash('error', 'Parent theme slug is invalid.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $cloneTheme = strtolower(trim((string) $this->input->text($post['clone_theme'] ?? null, 80)));
        // Optional clone-source slug must also be filesystem-safe.
        if ($cloneTheme !== '' && !$this->themeCatalog->isSafeSlug($cloneTheme)) {
            $this->context->flash('error', 'Clone-source theme slug is invalid.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themesRoot = $this->themeCatalog->root();
        $themeOptions = ThemeDiscovery::options($themesRoot);
        $themeManifests = ThemeDiscovery::manifests($themesRoot);
        // Parent theme must exist among discovered options.
        if ($parentTheme !== '' && !isset($themeOptions[$parentTheme])) {
            $this->context->flash('error', 'Selected parent theme was not found.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }
        // Clone-source theme must exist among discovered options.
        if ($cloneTheme !== '' && !isset($themeOptions[$cloneTheme])) {
            $this->context->flash('error', 'Selected clone-source theme was not found.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }
        // Child theme cannot declare itself as parent.
        if ($parentTheme === $themeSlug) {
            $this->context->flash('error', 'A child theme cannot use itself as parent.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $generateAgentsFile = isset($post['generate_agents']) && (string) $post['generate_agents'] === '1';
        $generateComposerFile = isset($post['generate_composer']) && (string) $post['generate_composer'] === '1';
        $generatePackageFile = isset($post['generate_package']) && (string) $post['generate_package'] === '1';
        $setActive = isset($post['set_active']) && (string) $post['set_active'] === '1';
        $themePath = $themesRoot . '/' . $themeSlug;
        // Refuse creation when target theme directory already exists.
        if (file_exists($themePath)) {
            $this->context->flash('error', 'A theme directory with this slug already exists.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $isChildTheme = $parentTheme !== '';
        $resolvedParentTheme = $parentTheme;
        // Infer parent metadata when cloning an existing child theme into standalone form.
        if ($cloneTheme !== '' && !$isChildTheme) {
            $cloneManifest = $themeManifests[$cloneTheme] ?? null;
            // Clone manifests can mark child themes and declare their parent slug.
            if (is_array($cloneManifest) && !empty($cloneManifest['is_child_theme'])) {
                $cloneParent = strtolower(trim((string) ($cloneManifest['parent_theme'] ?? '')));
                // Preserve clone parent when it exists and does not self-reference new slug.
                if ($cloneParent !== '' && $cloneParent !== $themeSlug && isset($themeOptions[$cloneParent])) {
                    $isChildTheme = true;
                    $resolvedParentTheme = $cloneParent;
                }
            }
        }

        // Scaffold/copy operations can fail due to filesystem or manifest writes.
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
            $this->directoryTree()->removeTree($themePath);
            $this->context->flash('error', 'Failed to create theme scaffold: ' . $exception->getMessage());
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        // Optionally activate newly created theme immediately after scaffold creation.
        if ($setActive) {
            // Activation persistence can fail independently of scaffold creation.
            try {
                ConfigWrite::persistValue($this->config->path(), $this->config->all(), 'site.theme', $themeSlug);
                $this->config = new Config($this->config->path());
            } catch (\RuntimeException $exception) {
                $this->directoryTree()->removeTree($themePath);
                $this->context->flash('error', 'Theme scaffold created, but activation failed: ' . $exception->getMessage());
                Redirect::redirect($this->context->panelUrl('/themes'));
            }
        }

        $message = 'Theme scaffold created at public/theme/' . $themeSlug . '/';
        // Include clone-source context when scaffold was copied from existing theme.
        if ($cloneTheme !== '') {
            $message .= ' (cloned from "' . $cloneTheme . '")';
        }
        $message .= $setActive ? ' and activated.' : '.';
        // Mention optional generated support files when requested.
        if ($generateAgentsFile || $generateComposerFile || $generatePackageFile) {
            $generated = [];
            // Agent helper files and symlinks are optional.
            if ($generateAgentsFile) {
                $generated[] = 'agents';
                $generated[] = 'AGENTS.md -> agents';
                $generated[] = 'CLAUDE.md -> agents';
            }
            // Composer metadata file is optional.
            if ($generateComposerFile) {
                $generated[] = 'composer.json';
            }
            // Package metadata file is optional.
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
        // Theme upload/install requires create permission.
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'create')) {
            return;
        }

        // CSRF validation protects theme upload/install actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $upload = $this->packageInstaller()->validateUpload(
            $files['theme_archive'] ?? null,
            'Theme archive',
            'Themes'
        );
        // Upload validator enforces archive shape/type/size.
        if (!(bool) ($upload['ok'] ?? false)) {
            $this->context->flash('error', (string) ($upload['error'] ?? 'Theme upload failed.'));
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $tmpPath = (string) ($upload['tmp_path'] ?? '');
        $archiveName = (string) ($upload['archive_name'] ?? 'theme-package.zip');
        // PHP temporary upload paths have no archive suffix, so retain the validated client filename for type detection.
        $derivedThemeSlug = $this->packageInstaller()->themeSlug($tmpPath, $archiveName);

        $slugResult = $this->packageInstaller()->resolveInstallName(
            (string) ($post['upload_slug'] ?? ''),
            $archiveName,
            fn (string $name): ?string => $derivedThemeSlug ?? $this->themeCatalog->slugFromArchiveFilename($name),
            fn (string $slug): bool => $this->themeCatalog->isSafeSlug($slug),
            fn (string $slug): bool => $this->themeCatalog->isStockSlug($slug),
            fn (string $slug): ?string => $this->themeCatalog->nextAvailableSlug($slug),
            fn (string $slug): bool => file_exists($this->themeCatalog->root() . '/' . $slug),
            'Theme',
            'Theme slug must use lowercase letters, numbers, underscores, or dashes.'
        );
        // Slug resolution may fail due conflicts/invalid metadata.
        if (!(bool) ($slugResult['ok'] ?? false)) {
            $slugError = (string) ($slugResult['error'] ?? 'Failed to resolve theme slug.');
            // Provide manifest-specific guidance when no slug can be inferred.
            if (
                trim((string) ($post['upload_slug'] ?? '')) === ''
                && $derivedThemeSlug === null
                && $this->themeCatalog->slugFromArchiveFilename($archiveName) === null
            ) {
                $slugError = 'Theme upload failed: theme.json must include a valid "slug" value or use Slug Override.';
            }

            $this->context->flash('error', $slugError);
            Redirect::redirect($this->context->panelUrl('/themes'));
        }
        $themeSlug = (string) ($slugResult['name'] ?? '');

        $themesRoot = $this->themeCatalog->root();
        // Ensure public/theme root exists before extraction.
        if (!is_dir($themesRoot) && !mkdir($themesRoot, 0775, true) && !is_dir($themesRoot)) {
            $this->context->flash('error', 'Failed to initialize public/theme directory.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $targetDirectory = $themesRoot . '/' . $themeSlug;
        // Create target install directory atomically.
        if (!mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->context->flash('error', 'Failed to create theme directory.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $extractError = $this->packageInstaller()->extractTo(
            $tmpPath,
            $targetDirectory,
            function (string $directory): void {
                $this->directoryTree()->removeTree($directory);
            },
            'theme',
            $archiveName
        );
        // Extraction errors include malformed archives and filesystem issues.
        if (is_string($extractError)) {
            $this->context->flash('error', $extractError);
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $flattenError = $this->packageInstaller()->flattenRoot($targetDirectory);
        // Flatten nested root folders so theme files live at install root.
        if (is_string($flattenError)) {
            $this->directoryTree()->removeTree($targetDirectory);
            $this->context->flash('error', $flattenError);
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $manifestPath = $targetDirectory . '/theme.json';
        // theme.json is required at root for discovery/validation.
        if (!is_file($manifestPath)) {
            $this->directoryTree()->removeTree($targetDirectory);
            $this->context->flash('error', 'Theme upload failed: archive must include theme.json at archive root.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $manifests = ThemeDiscovery::manifests($themesRoot);
        // Uploaded theme must parse as a valid discovered manifest row.
        if (!isset($manifests[$themeSlug])) {
            $this->directoryTree()->removeTree($targetDirectory);
            $this->context->flash('error', 'Theme upload failed: theme.json is missing required/valid metadata.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $message = 'Theme uploaded to public/theme/' . $themeSlug . '/. Enable it from the Installed Themes list when ready.';
        // Tell operators when slug was auto-renamed to avoid conflicts.
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
        // Theme export shares the same view permission as theme manager.
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'view')) {
            return;
        }

        $themeSlug = strtolower(trim((string) $this->input->text($query['theme'] ?? null, 80)));
        // Export target slug must be filesystem-safe.
        if (!$this->themeCatalog->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themePath = $this->themeCatalog->root() . '/' . $themeSlug;
        // Export only installed themes present on disk.
        if (!is_dir($themePath)) {
            $this->context->flash('error', 'Theme directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $format = strtolower(trim((string) $this->input->text($query['format'] ?? 'zip', 20)));

        // Archive generation can fail on format or filesystem errors.
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
        // Theme uninstall requires dedicated uninstall permission.
        if (!$this->context->requireRoutePermissionOrForbidden('themes', 'uninstall')) {
            return;
        }

        // CSRF validation protects theme uninstall actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themeSlug = strtolower(trim((string) $this->input->text($post['theme'] ?? null, 80)));
        // Uninstall target slug must be filesystem-safe.
        if (!$this->themeCatalog->isSafeSlug($themeSlug)) {
            $this->context->flash('error', 'Invalid theme identifier.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        // Bundled stock themes are protected from uninstall.
        if ($this->themeCatalog->isStockSlug($themeSlug)) {
            $this->context->flash('error', 'Stock themes cannot be uninstalled.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $themePath = $this->themeCatalog->root() . '/' . $themeSlug;
        // Uninstall target must exist on disk.
        if (!is_dir($themePath)) {
            $this->context->flash('error', 'Theme directory was not found on disk.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        // Prevent removal of currently active public theme.
        if ($this->themeCatalog->activeSlugFromConfig($this->config) === $themeSlug) {
            $this->context->flash('error', 'Active theme cannot be uninstalled. Enable another theme first.');
            Redirect::redirect($this->context->panelUrl('/themes'));
        }

        $this->directoryTree()->removeTree($themePath);
        // Verify deletion because recursive remove can partially fail.
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
        // Lazily initialize archive helper for upload/export operations only.
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
        // Lazily initialize theme scaffold generator for create/clone operations.
        if (!$this->themeGenerator instanceof ThemeGenerator) {
            $this->themeGenerator = new ThemeGenerator();
        }

        return $this->themeGenerator;
    }

    /**
     * Returns the package-install workflow service on first use.
     */
    private function packageInstaller(): ArchiveInstall
    {
        // Lazily initialize installer workflow for archive upload actions.
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
        // Lazily initialize recursive delete helper for uninstall/rollback flows.
        if (!$this->directoryTree instanceof ArchiveDelete) {
            $this->directoryTree = new ArchiveDelete();
        }

        return $this->directoryTree;
    }
}
