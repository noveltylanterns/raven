<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Error.php
 * Shared HTTP error renderer for both public and panel routing contexts.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Core\Config;
use Raven\Lib\View\Public\ThemeBrace;

/**
 * Renders standard HTTP error responses using the active public theme.
 *
 * Used by both the public and panel routing stacks so neither controller
 * needs to import public-theme or template-engine infrastructure directly.
 * Panel routes delegate here for unmatched or unauthorized URLs so guests
 * receive a public-themed error instead of a panel-specific page.
 */
final class Error
{
    private Config $config;
    private string $root;

    /**
     * @param Config $config Runtime configuration; provides the active theme slug and site context keys.
     * @param string $root   Absolute project root (directory containing public/, private/, etc.).
     */
    public function __construct(Config $config, string $root)
    {
        $this->config = $config;
        $this->root = rtrim($root, '/\\');
    }

    /**
     * Sets HTTP 404 status, renders the active theme's 404 + wrapper, and echoes the result.
     *
     * Falls back to plain-text "Not Found" when the active theme has no 404 template.
     *
     * @return void
     */
    public function render404(): void
    {
        $this->renderThemeTemplate(404, 'status/404', 'Not Found');
    }

    /**
     * Sets HTTP 403 status, renders the active theme's denied + wrapper, and echoes the result.
     *
     * Used for permission-denied and private-site access gates on public routes.
     * Falls back to plain-text "Forbidden" when the active theme has no denied template.
     *
     * @return void
     */
    public function renderDenied(): void
    {
        $this->renderThemeTemplate(403, 'status/denied', 'Forbidden');
    }

    /**
     * Sets HTTP 503 status, renders the active theme's disabled + wrapper, and echoes the result.
     *
     * Used for the site-disabled availability gate on public routes.
     * Falls back to plain-text "Service Unavailable" when the active theme has no disabled template.
     *
     * @return void
     */
    public function renderDisabled(): void
    {
        $this->renderThemeTemplate(503, 'status/disabled', 'Service Unavailable');
    }

    /**
     * Resolves the active public theme slug from config and discovered manifests.
     *
     * Prefers the configured slug when installed; falls back to 'raven' or the first available theme.
     *
     * @param string $themesRoot Absolute path to the public themes directory.
     * @return string            Active theme slug.
     */
    private function resolveActiveTheme(string $themesRoot): string
    {
        $configured = strtolower(trim((string) $this->config->get('site.theme', 'raven')));
        $options = Theme::options($themesRoot);

        if (isset($options[$configured])) {
            return $configured;
        }

        if (isset($options['raven'])) {
            return 'raven';
        }

        $slugs = array_keys($options);
        return (string) ($slugs[0] ?? 'raven');
    }

    /**
     * Resolves the CSS slug by walking the inheritance chain to find the first theme with style.css.
     *
     * @param string $themesRoot  Absolute path to the public themes directory.
     * @param string $activeTheme Active theme slug to start the inheritance walk from.
     * @return string             Slug of the theme that owns the active stylesheet.
     */
    private function resolveCssSlug(string $themesRoot, string $activeTheme): string
    {
        $chain = Theme::inheritanceChain($themesRoot, $activeTheme);
        if ($chain === []) {
            $chain = [$activeTheme];
        }

        foreach ($chain as $candidate) {
            if (is_file($themesRoot . '/' . $candidate . '/css/style.css')) {
                return $candidate;
            }
        }

        return $activeTheme;
    }

    /**
     * Sets HTTP status, renders the named template from the active theme + wrapper, and echoes.
     *
     * Falls back to a plain-text response with the given fallback text when the template is missing.
     *
     * @param int    $status       HTTP status code to set.
     * @param string $template     Template path relative to the theme/tpl lookup root (e.g. 'status/404').
     * @param string $fallbackText Plain-text body used when the theme has no matching template.
     * @return void
     */
    private function renderThemeTemplate(int $status, string $template, string $fallbackText): void
    {
        http_response_code($status);

        $themesRoot = $this->root . '/public/theme';
        $coreFallbackRoot = $this->root . '/private/tpl';
        $activeTheme = $this->resolveActiveTheme($themesRoot);
        $themeBrace = new ThemeBrace($this->root . '/.tmp/template_tag_cache');
        $templateFile = $this->resolveTemplateFile($template, $themesRoot, $coreFallbackRoot, $activeTheme);
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $fallbackText;
            return;
        }

        $site = (new SiteContextBuilder())->publicFallback(
            $this->config,
            $activeTheme,
            $this->resolveCssSlug($themesRoot, $activeTheme)
        );

        $content = $themeBrace->renderFile($templateFile, ['site' => $site]);

        $layoutFile = $this->resolveTemplateFile('wrapper', $themesRoot, $coreFallbackRoot, $activeTheme);
        if ($layoutFile === null) {
            echo $content;
            return;
        }

        echo $themeBrace->renderFile($layoutFile, ['site' => $site, 'content' => $content]);
    }

    /**
     * Resolves one template file from the active theme chain plus the core fallback root.
     *
     * @param string $template Relative template path without the `.php` extension.
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $coreFallbackRoot Absolute path to the core template root.
     * @param string $activeThemeSlug Active public-theme slug.
     * @return string|null Absolute template file path, or null when no candidate exists.
     */
    private function resolveTemplateFile(
        string $template,
        string $themesRoot,
        string $coreFallbackRoot,
        string $activeThemeSlug
    ): ?string {
        $relative = trim($template, '/') . '.php';
        foreach ($this->templateRoots($themesRoot, $coreFallbackRoot, $activeThemeSlug) as $root) {
            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the lookup roots used by shared public-theme error rendering.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $coreFallbackRoot Absolute path to the core template root.
     * @param string $activeThemeSlug Active public-theme slug.
     * @return array<int, string> Theme tpl roots followed by the core fallback root.
     */
    private function templateRoots(string $themesRoot, string $coreFallbackRoot, string $activeThemeSlug): array
    {
        $roots = [];
        $chain = Theme::inheritanceChain($themesRoot, $activeThemeSlug);
        if ($chain === []) {
            $chain = [$activeThemeSlug];
        }

        foreach ($chain as $candidateThemeSlug) {
            $themeViewsRoot = rtrim($themesRoot, '/\\') . '/' . $candidateThemeSlug . '/tpl';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        $roots[] = rtrim($coreFallbackRoot, '/\\');
        return $roots;
    }
}
