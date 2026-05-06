<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/ThemeTemplate.php
 * Theme-aware public template lookup and render orchestration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Resolves and renders public templates across the active theme chain.
 */
final class ThemeTemplate
{
    private const TEMPLATE_REDIRECT_PREFIX = '__RVN_TEMPLATE_REDIRECT__:';
    private InputSanitizer $input;

    /**
     * Per-request cache of resolved template paths.
     *
     * Keyed by a hash of [template, ...roots] so repeated calls with the same
     * arguments — common on pages with multiple partials or child-theme chains —
     * skip the full `is_file()` fallback loop. Null values are cached to avoid
     * re-walking the chain for known-missing templates. Scope is one request
     * (one resolver instance); no cross-request state is stored here.
     *
     * @var array<string, string|null>
     */
    private array $resolvedCache = [];

    /**
     * @param InputSanitizer $input Shared request sanitizer used for slug-safe template overrides.
     * @return void
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Returns the active theme-chain lookup roots plus the core fallback root.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @param string $coreViewsRoot Absolute path to the core template root.
     * @return array<int, string> Theme tpl roots followed by the core fallback root.
     */
    public function lookupRoots(string $themesRoot, string $activeThemeSlug, string $coreViewsRoot): array
    {
        $themeViewsRoots = $this->currentThemeViewsRoots($themesRoot, $activeThemeSlug);
        return [...$themeViewsRoots, rtrim($coreViewsRoot, '/\\')];
    }

    /**
     * Returns the existing tpl roots for the active theme inheritance chain.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @return array<int, string> Existing theme tpl roots in child-first order.
     */
    public function currentThemeViewsRoots(string $themesRoot, string $activeThemeSlug): array
    {
        $roots = [];
        foreach ($this->currentThemeInheritanceChain($themesRoot, $activeThemeSlug) as $candidateThemeSlug) {
            $themeViewsRoot = rtrim($themesRoot, '/\\') . '/' . $candidateThemeSlug . '/tpl';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        return $roots;
    }

    /**
     * Resolves a template name to an absolute file path by walking the provided roots.
     *
     * Results are memoized for the lifetime of this instance (one request) to avoid
     * repeated `is_file()` syscalls for the same template across multiple call sites
     * (e.g. partials resolved more than once, or child-theme chains re-walked).
     * Both hits and misses (null) are cached so re-walking never occurs.
     *
     * @param string $template Relative template name without leading slash or `.php` extension.
     * @param string ...$roots Absolute directory paths to search in priority order.
     * @return string|null Absolute path to the first matching file, or null if not found in any root.
     */
    public function resolveTemplateFile(string $template, string ...$roots): ?string
    {
        // Cache key encodes both the template name and the full root list so different
        // theme chains for the same template name do not collide in the cache.
        $cacheKey = md5($template . "\0" . implode("\0", $roots));

        if (array_key_exists($cacheKey, $this->resolvedCache)) {
            return $this->resolvedCache[$cacheKey];
        }

        $relative = trim($template, '/') . '.php';
        $resolved = null;

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }

            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                $resolved = $candidate;
                break;
            }
        }

        // Store null for misses so repeated calls for the same missing template are also cached.
        $this->resolvedCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * Resolves the best channel template name from slug-specific and default candidates.
     *
     * @param string $channelSlug Channel slug used for slug-specific template overrides.
     * @param string ...$lookupRoots Absolute directory paths to search in priority order.
     * @return string Resolved relative template name.
     */
    public function resolveChannelTemplateName(string $channelSlug, string ...$lookupRoots): string
    {
        $normalizedSlug = $this->input->slug($channelSlug);
        if ($normalizedSlug === null) {
            return 'channel/index';
        }

        $slugTemplate = 'channel/' . $normalizedSlug;
        if ($this->resolveTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'channel/index';
    }

    /**
     * Resolves the best page template name from channel-specific and default candidates.
     *
     * @param string|null $channelSlug Optional channel slug used for page-template overrides.
     * @param string ...$lookupRoots Absolute directory paths to search in priority order.
     * @return string Resolved relative template name.
     */
    public function resolvePageTemplateName(?string $channelSlug, string ...$lookupRoots): string
    {
        if ($channelSlug !== null) {
            $normalizedSlug = $this->input->slug($channelSlug);
            if ($normalizedSlug !== null) {
                $channelTemplate = 'page/' . $normalizedSlug;
                if ($this->resolveTemplateFile($channelTemplate, ...$lookupRoots) !== null) {
                    return $channelTemplate;
                }
            }
        }

        return 'page/index';
    }

    /**
     * Resolves the best category template name from slug-specific and default candidates.
     *
     * @param string $categorySlug Category slug used for slug-specific template overrides.
     * @param string ...$lookupRoots Absolute directory paths to search in priority order.
     * @return string Resolved relative template name.
     */
    public function resolveCategoryTemplateName(string $categorySlug, string ...$lookupRoots): string
    {
        $normalizedSlug = $this->input->slug($categorySlug);
        if ($normalizedSlug === null) {
            return 'category/index';
        }

        $slugTemplate = 'category/' . $normalizedSlug;
        if ($this->resolveTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'category/index';
    }

    /**
     * Resolves the best tag template name from slug-specific and default candidates.
     *
     * @param string $tagSlug Tag slug used for slug-specific template overrides.
     * @param string ...$lookupRoots Absolute directory paths to search in priority order.
     * @return string Resolved relative template name.
     */
    public function resolveTagTemplateName(string $tagSlug, string ...$lookupRoots): string
    {
        $normalizedSlug = $this->input->slug($tagSlug);
        if ($normalizedSlug === null) {
            return 'tag/index';
        }

        $slugTemplate = 'tag/' . $normalizedSlug;
        if ($this->resolveTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'tag/index';
    }

    /**
     * Resolves one channel template name across the active theme chain.
     *
     * @param string $channelSlug Channel slug used for slug-specific template overrides.
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @param string $coreViewsRoot Absolute path to the core template root.
     * @return string Resolved relative template name.
     */
    public function resolveChannelTemplateNameForThemeChain(
        string $channelSlug,
        string $themesRoot,
        string $activeThemeSlug,
        string $coreViewsRoot
    ): string {
        return $this->resolveChannelTemplateName(
            $channelSlug,
            ...$this->lookupRoots($themesRoot, $activeThemeSlug, $coreViewsRoot)
        );
    }

    /**
     * Resolves one page template name across the active theme chain.
     *
     * @param string|null $channelSlug Optional channel slug used for page-template overrides.
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @param string $coreViewsRoot Absolute path to the core template root.
     * @return string Resolved relative template name.
     */
    public function resolvePageTemplateNameForThemeChain(
        ?string $channelSlug,
        string $themesRoot,
        string $activeThemeSlug,
        string $coreViewsRoot
    ): string {
        return $this->resolvePageTemplateName(
            $channelSlug,
            ...$this->lookupRoots($themesRoot, $activeThemeSlug, $coreViewsRoot)
        );
    }

    /**
     * Resolves one category template name across the active theme chain.
     *
     * @param string $categorySlug Category slug used for slug-specific template overrides.
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @param string $coreViewsRoot Absolute path to the core template root.
     * @return string Resolved relative template name.
     */
    public function resolveCategoryTemplateNameForThemeChain(
        string $categorySlug,
        string $themesRoot,
        string $activeThemeSlug,
        string $coreViewsRoot
    ): string {
        return $this->resolveCategoryTemplateName(
            $categorySlug,
            ...$this->lookupRoots($themesRoot, $activeThemeSlug, $coreViewsRoot)
        );
    }

    /**
     * Resolves one tag template name across the active theme chain.
     *
     * @param string $tagSlug Tag slug used for slug-specific template overrides.
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @param string $coreViewsRoot Absolute path to the core template root.
     * @return string Resolved relative template name.
     */
    public function resolveTagTemplateNameForThemeChain(
        string $tagSlug,
        string $themesRoot,
        string $activeThemeSlug,
        string $coreViewsRoot
    ): string {
        return $this->resolveTagTemplateName(
            $tagSlug,
            ...$this->lookupRoots($themesRoot, $activeThemeSlug, $coreViewsRoot)
        );
    }

    /**
     * Renders one resolved template and optional layout across the provided lookup roots.
     *
     * @param string $template Relative template name without leading slash or `.php` extension.
     * @param array<string, mixed> $data Template payload.
     * @param string|null $layout Optional layout template name.
     * @param callable(string, array<string, mixed>): string $renderFile Callable that renders one absolute file path.
     * @param string ...$lookupRoots Absolute directory paths to search in priority order.
     * @return string Rendered template output.
     */
    public function render(
        string $template,
        array $data,
        ?string $layout,
        callable $renderFile,
        string ...$lookupRoots
    ): string {
        $content = $this->renderResolvedTemplate($template, $data, $renderFile, 0, ...$lookupRoots);
        if ($layout === null) {
            return $content;
        }

        $layoutFile = $this->resolveTemplateFile($layout, ...$lookupRoots);
        if ($layoutFile === null) {
            throw new RuntimeException('Public layout not found: ' . $layout);
        }

        $layoutData = $data;
        $layoutData['content'] = $content;
        return $renderFile($layoutFile, $layoutData);
    }

    /**
     * Renders one template across the active theme chain plus the core fallback root.
     *
     * @param string $template Relative template name without leading slash or `.php` extension.
     * @param array<string, mixed> $data Template payload.
     * @param string|null $layout Optional layout template name.
     * @param callable(string, array<string, mixed>): string $renderFile Callable that renders one absolute file path.
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $activeThemeSlug Active public-theme slug.
     * @param string $coreViewsRoot Absolute path to the core template root.
     * @return string Rendered template output.
     */
    public function renderForThemeChain(
        string $template,
        array $data,
        ?string $layout,
        callable $renderFile,
        string $themesRoot,
        string $activeThemeSlug,
        string $coreViewsRoot
    ): string {
        return $this->render(
            $template,
            $data,
            $layout,
            $renderFile,
            ...$this->lookupRoots($themesRoot, $activeThemeSlug, $coreViewsRoot)
        );
    }

    /**
     * Resolves the active public-theme inheritance chain.
     *
     * @param string $themesRoot Absolute path to the public-theme root directory.
     * @param string $themeSlug Active public-theme slug.
     * @return array<int, string> Child-first inheritance chain.
     */
    private function currentThemeInheritanceChain(string $themesRoot, string $themeSlug): array
    {
        $chain = ThemeDiscovery::inheritanceChain(rtrim($themesRoot, '/\\'), $themeSlug);
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }

    /**
     * Renders one resolved template file and follows template redirects when present.
     *
     * @param string $template Relative template name without leading slash or `.php` extension.
     * @param array<string, mixed> $data Template payload.
     * @param callable(string, array<string, mixed>): string $renderFile Callable that renders one absolute file path.
     * @param int $depth Current redirect depth guard.
     * @param string ...$lookupRoots Absolute directory paths to search in priority order.
     * @return string Rendered template output after redirect resolution.
     */
    private function renderResolvedTemplate(
        string $template,
        array $data,
        callable $renderFile,
        int $depth,
        string ...$lookupRoots
    ): string {
        if ($depth > 4) {
            throw new RuntimeException('Public template redirect depth exceeded for: ' . $template);
        }

        $templateFile = $this->resolveTemplateFile($template, ...$lookupRoots);
        if ($templateFile === null) {
            throw new RuntimeException('Public template not found: ' . $template);
        }

        $content = $renderFile($templateFile, $data);
        $redirectTemplate = $this->templateRedirectTarget($content);
        if ($redirectTemplate === null || $redirectTemplate === $template) {
            return $content;
        }

        $this->applyTemplateRedirectStatus($redirectTemplate);
        return $this->renderResolvedTemplate($redirectTemplate, $data, $renderFile, $depth + 1, ...$lookupRoots);
    }

    /**
     * Extracts one internal template-redirect marker from rendered content.
     *
     * @param string $content Rendered template output.
     * @return string|null Redirect target template, or null when no redirect marker is present.
     */
    private function templateRedirectTarget(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $pattern = '/' . preg_quote(self::TEMPLATE_REDIRECT_PREFIX, '/') . '\s*([A-Za-z0-9_\/-]+)/';
        if (preg_match($pattern, $content, $matches) !== 1) {
            return null;
        }

        $target = trim((string) ($matches[1] ?? ''));
        return $target === '' ? null : $target;
    }

    /**
     * Applies HTTP status overrides for internal template redirects to stock status views.
     *
     * @param string $template Redirect target template name.
     * @return void
     */
    private function applyTemplateRedirectStatus(string $template): void
    {
        $status = match ($template) {
            'status/denied' => 403,
            'status/404' => 404,
            'status/disabled' => 503,
            default => null,
        };

        if (is_int($status)) {
            http_response_code($status);
        }
    }
}
