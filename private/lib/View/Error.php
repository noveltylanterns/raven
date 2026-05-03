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
        $coreFallbackRoot = $this->root . '/private/tpl/public';
        $activeTheme = $this->resolveActiveTheme($themesRoot);
        $themeBrace = new ThemeBrace($this->root . '/.tmp/template_tag_cache');
        $templateFile = $this->resolveTemplateFile($template, $themesRoot, $coreFallbackRoot, $activeTheme);
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $fallbackText;
            return;
        }

        $site = $this->publicFallbackSiteData(
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

    /**
     * Builds the minimal public `site` payload needed by themed error templates.
     *
     * Error rendering happens outside the normal public controller wrapper flow, so
     * it assembles the fallback public payload locally instead of depending on a
     * shared cross-runtime view helper.
     *
     * @param string $publicTheme Resolved active public-theme slug.
     * @param string $publicThemeActive Theme slug that owns the active stylesheet.
     * @return array<string, string> Template-ready public wrapper payload.
     */
    private function publicFallbackSiteData(string $publicTheme, string $publicThemeActive): array
    {
        $siteUrl = $this->siteUrlFromConfig();

        return array_merge($this->publicMetaBase($publicTheme, $publicThemeActive), [
            'url' => $siteUrl,
            'current_url' => '',
            'theme_url' => $this->themeUrl($siteUrl, $publicThemeActive),
            'meta_image' => $this->defaultMetaImageFromConfig(),
        ]);
    }

    /**
     * Builds the shared config-owned public meta keys used by themed error templates.
     *
     * @param string $publicTheme Resolved active public-theme slug.
     * @param string $publicThemeActive Theme slug that owns the active stylesheet.
     * @return array<string, string> Shared public wrapper payload keys.
     */
    private function publicMetaBase(string $publicTheme, string $publicThemeActive): array
    {
        return [
            'name' => (string) $this->config->get('site.name', 'Raven CMS'),
            'protocol' => $this->siteProtocolFromConfig(),
            'domain' => (string) $this->config->get('site.domain', 'localhost'),
            'panel_path' => (string) $this->config->get('panel.path', 'panel'),
            'apple_touch_icon' => trim((string) $this->config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $this->config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $this->config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $this->config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $this->config->get('meta.twitter.creator', '')),
            'og_type' => trim((string) $this->config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $this->config->get('meta.opengraph.locale', 'en_US')),
            'theme' => $publicTheme,
            'theme_active' => $publicThemeActive,
        ];
    }

    /**
     * Returns the configured default social-image value for wrapper fallbacks.
     *
     * @return string Configured fallback image URL/path.
     */
    private function defaultMetaImageFromConfig(): string
    {
        return trim((string) $this->config->get('meta.image', ''));
    }

    /**
     * Normalizes the configured site protocol to one HTTP scheme.
     *
     * @return string `http` or `https`.
     */
    private function siteProtocolFromConfig(): string
    {
        $protocol = strtolower(trim((string) $this->config->get('site.protocol', 'https')));
        return in_array($protocol, ['http', 'https'], true) ? $protocol : 'https';
    }

    /**
     * Resolves the configured absolute site URL, preserving any install subdirectory.
     *
     * @return string Absolute site base URL derived from config.
     */
    private function siteUrlFromConfig(): string
    {
        $domain = trim((string) $this->config->get('site.domain', 'localhost'));
        if ($domain === '') {
            $domain = 'localhost';
        }

        $path = '';
        if (str_contains($domain, '://')) {
            $parsedHost = trim((string) parse_url($domain, PHP_URL_HOST));
            $parsedPort = parse_url($domain, PHP_URL_PORT);
            $parsedPath = (string) parse_url($domain, PHP_URL_PATH);
            if ($parsedHost !== '') {
                $domain = $parsedHost . (is_int($parsedPort) && $parsedPort > 0 ? ':' . $parsedPort : '');
                $path = '/' . trim($parsedPath, '/');
                $path = $path === '/' ? '' : $path;
            } else {
                $domain = 'localhost';
            }
        } else {
            if (str_contains($domain, '/')) {
                $parts = explode('/', $domain, 2);
                $domain = (string) ($parts[0] ?? '');
                $path = '/' . trim((string) ($parts[1] ?? ''), '/');
                $path = $path === '/' ? '' : $path;
            }

            // Strip any accidental query/fragment tails before rebuilding the canonical base URL.
            $domain = preg_replace('/[\/?#].*$/', '', $domain) ?? $domain;
            $domain = trim($domain);
            if ($domain === '') {
                $domain = 'localhost';
            }
        }

        return $this->siteProtocolFromConfig() . '://' . $domain . $path;
    }

    /**
     * Builds the public theme asset base URL from one resolved site URL.
     *
     * @param string $siteUrl Absolute site base URL.
     * @param string $themeCssSlug Theme slug that owns the active stylesheet.
     * @return string Absolute theme asset base URL.
     */
    private function themeUrl(string $siteUrl, string $themeCssSlug): string
    {
        $themeCssSlug = trim($themeCssSlug);
        if ($themeCssSlug === '') {
            $themeCssSlug = 'raven';
        }

        return rtrim($siteUrl, '/') . '/theme/' . rawurlencode($themeCssSlug);
    }
}
