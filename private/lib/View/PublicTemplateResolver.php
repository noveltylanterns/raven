<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Lib\Security\InputSanitizer;

/**
 * Resolves public template lookup roots and slug-specific template overrides.
 */
final class PublicTemplateResolver
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @return array<int, string>
     */
    public function currentThemeViewsRoots(string $themesRoot, string $activeThemeSlug): array
    {
        $roots = [];
        foreach ($this->currentThemeInheritanceChain($themesRoot, $activeThemeSlug) as $candidateThemeSlug) {
            $themeViewsRoot = rtrim($themesRoot, '/\\') . '/' . $candidateThemeSlug . '/vis';
            if (is_dir($themeViewsRoot)) {
                $roots[] = $themeViewsRoot;
            }
        }

        return $roots;
    }

    public function resolveTemplateFile(string $template, string ...$roots): ?string
    {
        $relative = trim($template, '/') . '.php';

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }

            $candidate = rtrim($root, '/\\') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function resolveChannelTemplateName(string $channelSlug, string ...$lookupRoots): string
    {
        $normalizedSlug = $this->input->slug($channelSlug);
        if ($normalizedSlug === null) {
            return 'channels/index';
        }

        $slugTemplate = 'channels/' . $normalizedSlug;
        if ($this->resolveTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'channels/index';
    }

    public function resolvePageTemplateName(?string $channelSlug, string ...$lookupRoots): string
    {
        if ($channelSlug !== null) {
            $normalizedSlug = $this->input->slug($channelSlug);
            if ($normalizedSlug !== null) {
                $channelTemplate = 'pages/' . $normalizedSlug;
                if ($this->resolveTemplateFile($channelTemplate, ...$lookupRoots) !== null) {
                    return $channelTemplate;
                }
            }
        }

        return 'pages/index';
    }

    public function resolveCategoryTemplateName(string $categorySlug, string ...$lookupRoots): string
    {
        $normalizedSlug = $this->input->slug($categorySlug);
        if ($normalizedSlug === null) {
            return 'categories/index';
        }

        $slugTemplate = 'categories/' . $normalizedSlug;
        if ($this->resolveTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'categories/index';
    }

    public function resolveTagTemplateName(string $tagSlug, string ...$lookupRoots): string
    {
        $normalizedSlug = $this->input->slug($tagSlug);
        if ($normalizedSlug === null) {
            return 'tags/index';
        }

        $slugTemplate = 'tags/' . $normalizedSlug;
        if ($this->resolveTemplateFile($slugTemplate, ...$lookupRoots) !== null) {
            return $slugTemplate;
        }

        return 'tags/index';
    }

    /**
     * @return array<int, string>
     */
    private function currentThemeInheritanceChain(string $themesRoot, string $themeSlug): array
    {
        $chain = PublicThemeRegistry::inheritanceChain(rtrim($themesRoot, '/\\'), $themeSlug);
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }
}
