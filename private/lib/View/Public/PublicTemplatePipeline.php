<?php

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use RuntimeException;

/**
 * Shared public template lookup/render pipeline over resolved theme roots.
 */
final class PublicTemplatePipeline
{
    private const TEMPLATE_REDIRECT_PREFIX = '__RVN_TEMPLATE_REDIRECT__:';
    private PublicTemplateResolver $resolver;

    public function __construct(PublicTemplateResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @return array<int, string>
     */
    public function lookupRoots(string $themesRoot, string $activeThemeSlug, string $coreViewsRoot): array
    {
        $themeViewsRoots = $this->resolver->currentThemeViewsRoots($themesRoot, $activeThemeSlug);
        return [...$themeViewsRoots, rtrim($coreViewsRoot, '/\\')];
    }

    public function resolveTemplateFile(string $template, string ...$lookupRoots): ?string
    {
        return $this->resolver->resolveTemplateFile($template, ...$lookupRoots);
    }

    public function resolveChannelTemplateName(string $channelSlug, string ...$lookupRoots): string
    {
        return $this->resolver->resolveChannelTemplateName($channelSlug, ...$lookupRoots);
    }

    public function resolvePageTemplateName(?string $channelSlug, string ...$lookupRoots): string
    {
        return $this->resolver->resolvePageTemplateName($channelSlug, ...$lookupRoots);
    }

    public function resolveCategoryTemplateName(string $categorySlug, string ...$lookupRoots): string
    {
        return $this->resolver->resolveCategoryTemplateName($categorySlug, ...$lookupRoots);
    }

    public function resolveTagTemplateName(string $tagSlug, string ...$lookupRoots): string
    {
        return $this->resolver->resolveTagTemplateName($tagSlug, ...$lookupRoots);
    }

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
     * @param array<string, mixed> $data
     * @param callable(string, array<string, mixed>): string $renderFile
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
     * @param array<string, mixed> $data
     * @param callable(string, array<string, mixed>): string $renderFile
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

    /**
     * @param array<string, mixed> $data
     * @param callable(string, array<string, mixed>): string $renderFile
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
}
