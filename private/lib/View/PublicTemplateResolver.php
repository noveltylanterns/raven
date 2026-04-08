<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\PublicThemeRegistry;

/**
 * Resolves public template lookup roots and slug-specific template overrides.
 */
final class PublicTemplateResolver
{
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
