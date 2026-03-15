<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use RuntimeException;

/**
 * Shared public template lookup/render pipeline over resolved theme roots.
 */
final class PublicTemplatePipeline
{
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
        $templateFile = $this->resolveTemplateFile($template, ...$lookupRoots);
        if ($templateFile === null) {
            throw new RuntimeException('Public template not found: ' . $template);
        }

        $content = $renderFile($templateFile, $data);
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
}
