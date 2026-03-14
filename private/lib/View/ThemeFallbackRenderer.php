<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Core\View\TemplateTagEngine;

/**
 * Shared resolver/renderer for public-theme fallback template files.
 */
final class ThemeFallbackRenderer
{
    private string $themesRoot;
    private string $coreFallbackRoot;
    private TemplateTagEngine $templateTags;

    public function __construct(string $themesRoot, string $coreFallbackRoot, string $templateTagCachePath)
    {
        $this->themesRoot = rtrim($themesRoot, '/\\');
        $this->coreFallbackRoot = rtrim($coreFallbackRoot, '/\\');
        $this->templateTags = new TemplateTagEngine($templateTagCachePath);
    }

    public function resolveTemplateFile(string $template, string $activeThemeSlug): ?string
    {
        $relative = trim($template, '/') . '.php';
        foreach ($this->templateRoots($activeThemeSlug) as $root) {
            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderFile(string $file, array $data): string
    {
        return $this->templateTags->renderFile($file, $data);
    }

    /**
     * @return array<int, string>
     */
    private function templateRoots(string $activeThemeSlug): array
    {
        $roots = [];

        $chain = PublicThemeRegistry::inheritanceChain($this->themesRoot, $activeThemeSlug);
        if ($chain === []) {
            $chain = [$activeThemeSlug];
        }

        foreach ($chain as $candidateThemeSlug) {
            $themeViewsRoot = $this->themesRoot . '/' . $candidateThemeSlug . '/vis';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        $roots[] = $this->coreFallbackRoot;

        return $roots;
    }
}
