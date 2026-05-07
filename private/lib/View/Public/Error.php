<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/Error.php
 * Public-themed HTTP error renderer shared by public and panel route gates.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Core\Config;
use Raven\Core\Router\FeedPolicy;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Request;

/**
 * Renders stock HTTP errors through the active public theme.
 */
final class Error
{
    private Config $config;
    private string $root;
    private ThemeCatalog $themeCatalog;
    private ThemeTemplate $themeTemplate;
    private ThemeBrace $themeBrace;
    private Meta $meta;

    /**
     * @param Config $config Runtime configuration reader.
     * @param string $root Absolute project root (directory containing public/, private/, etc.).
     * @param ThemeCatalog|null $themeCatalog Optional injected public-theme catalog.
     * @param ThemeTemplate|null $themeTemplate Optional injected public template resolver.
     * @param ThemeBrace|null $themeBrace Optional injected template renderer.
     * @param Meta|null $meta Optional injected public meta payload service.
     * @return void
     */
    public function __construct(
        Config $config,
        string $root,
        ?ThemeCatalog $themeCatalog = null,
        ?ThemeTemplate $themeTemplate = null,
        ?ThemeBrace $themeBrace = null,
        ?Meta $meta = null
    ) {
        $this->config = $config;
        $this->root = rtrim($root, '/\\');

        $input = new InputSanitizer();
        $this->themeCatalog = $themeCatalog ?? new ThemeCatalog($this->root . '/public/theme', $input);
        $this->themeTemplate = $themeTemplate ?? new ThemeTemplate($input);
        $this->themeBrace = $themeBrace ?? new ThemeBrace($this->root . '/.tmp/template_tag_cache');
        $this->meta = $meta ?? new Meta(
            new Request(),
            $this->themeCatalog,
            new UserProfileParser($input),
            new FeedPolicy($this->config, $input)
        );
    }

    /**
     * Renders the active public-theme 404 error view.
     *
     * @return void
     */
    public function render404(): void
    {
        $this->renderStatusTemplate(404, 'status/404', 'Not Found');
    }

    /**
     * Renders the active public-theme permission-denied error view.
     *
     * @return void
     */
    public function renderDenied(): void
    {
        $this->renderStatusTemplate(403, 'status/denied', 'Forbidden');
    }

    /**
     * Renders the active public-theme site-disabled error view.
     *
     * @return void
     */
    public function renderDisabled(): void
    {
        $this->renderStatusTemplate(503, 'status/disabled', 'Service Unavailable');
    }

    /**
     * Applies one status code, renders one template through theme inheritance, and echoes output.
     *
     * @param int $status HTTP status code to set.
     * @param string $template Template path relative to theme `tpl/` root.
     * @param string $fallbackText Plain-text fallback when no themed template exists.
     * @return void
     */
    private function renderStatusTemplate(int $status, string $template, string $fallbackText): void
    {
        http_response_code($status);

        $activeTheme = $this->themeCatalog->activeSlugFromConfig($this->config);
        $lookupRoots = $this->themeTemplate->lookupRoots(
            $this->themeCatalog->root(),
            $activeTheme,
            $this->root . '/private/tpl/public'
        );

        $templateFile = $this->themeTemplate->resolveTemplateFile($template, ...$lookupRoots);
        if ($templateFile === null) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $fallbackText;
            return;
        }

        $site = $this->meta->siteData($this->config);
        $content = $this->themeBrace->renderFile($templateFile, ['site' => $site]);

        $layoutFile = $this->themeTemplate->resolveTemplateFile('wrapper', ...$lookupRoots);
        if ($layoutFile === null) {
            echo $content;
            return;
        }

        echo $this->themeBrace->renderFile($layoutFile, ['site' => $site, 'content' => $content]);
    }
}
