<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/ThemeCatalog.php
 * Shared catalog, inheritance, and slug-policy helpers for public themes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared public-theme catalog and slug policy helper.
 */
final class ThemeCatalog
{
    private string $themesRoot;
    private InputSanitizer $input;
    /** @var array<int, string> */
    private array $stockSlugs;

    /**
     * @param array<int, string> $stockSlugs
     */
    public function __construct(string $themesRoot, InputSanitizer $input, array $stockSlugs = ['raven'])
    {
        $this->themesRoot = rtrim($themesRoot, '/\\');
        $this->input = $input;
        $this->stockSlugs = array_values(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            array_filter($stockSlugs, static fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
        )));
    }

    /**
     * Returns the filesystem root where public themes are discovered.
     *
     * @return string Absolute public-theme root path.
     */
    public function root(): string
    {
        return $this->themesRoot;
    }

    /**
     * Returns the installed public-theme option map.
     *
     * @return array<string, string> Map of theme slug to human-readable name.
     */
    public function options(): array
    {
        $options = ThemeDiscovery::options($this->themesRoot);
        // Provide stock fallback option when no installable manifests were discovered.
        if ($options === []) {
            return ['raven' => 'Raven Basic'];
        }

        return $options;
    }

    /**
     * Resolves the active public-theme slug from runtime config.
     *
     * @param Config $config Runtime configuration reader.
     * @return string Active public-theme slug.
     */
    public function activeSlugFromConfig(Config $config): string
    {
        $configured = strtolower($this->input->text((string) $config->get('site.theme', 'raven'), 80));
        $options = $this->options();

        // Use configured slug when it exists in discovered options.
        if (isset($options[$configured])) {
            return $configured;
        }

        // Fall back to canonical raven theme when available.
        if (isset($options['raven'])) {
            return 'raven';
        }

        $slugs = array_keys($options);
        return (string) ($slugs[0] ?? 'raven');
    }

    /**
     * Resolves a channel theme override through the same installed-theme fallback as the global site theme.
     *
     * The `inherit` sentinel delegates to `site.theme`; a missing or removed explicit theme
     * also delegates to the global selection so old channel records never break rendering.
     *
     * @param string $override Stored channel override slug or `inherit` sentinel.
     * @param Config $config Runtime configuration reader containing the global site theme.
     * @return string Effective installed public-theme slug.
     */
    public function resolveOverrideSlug(string $override, Config $config): string
    {
        $normalized = strtolower(trim($override));
        $options = $this->options();
        // An explicit override is usable only while its manifest remains installed.
        if ($normalized !== '' && $normalized !== 'inherit' && isset($options[$normalized])) {
            return $normalized;
        }

        // Inherit and stale selections follow the exact global-theme fallback path.
        return $this->activeSlugFromConfig($config);
    }

    /**
     * Returns the child-first inheritance chain for one public theme.
     *
     * @param string $themeSlug Theme slug to resolve.
     * @return array<int, string> Child-first inheritance chain.
     */
    public function inheritanceChain(string $themeSlug): array
    {
        $chain = ThemeDiscovery::inheritanceChain($this->themesRoot, $themeSlug);
        // Unknown themes still return at least the requested slug for deterministic callers.
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }

    /**
     * Resolves the theme slug that owns the active stylesheet for one theme chain.
     *
     * @param string $themeSlug Active public-theme slug.
     * @return string Slug of the first theme in the chain that provides `css/style.css`.
     */
    public function cssSlug(string $themeSlug): string
    {
        // First theme in chain with css/style.css owns active stylesheet URL slug.
        foreach ($this->inheritanceChain($themeSlug) as $candidateThemeSlug) {
            $cssPath = $this->themesRoot . '/' . $candidateThemeSlug . '/css/style.css';
            // Return immediately when candidate provides a concrete stylesheet.
            if (is_file($cssPath)) {
                return $candidateThemeSlug;
            }
        }

        return $themeSlug;
    }

    /**
     * Builds the panel-facing inventory row set for installed public themes.
     *
     * @return array<int, array{
     *   slug: string,
     *   name: string,
     *   is_stock: bool,
     *   is_child_theme: bool,
     *   parent_theme: string,
     *   has_css: bool,
     *   has_wrapper: bool,
     *   inheritance_chain: string
     * }>
     */
    public function listForPanel(): array
    {
        $manifests = ThemeDiscovery::manifests($this->themesRoot);
        $rows = [];

        // Build panel inventory rows from each discovered manifest entry.
        foreach ($manifests as $slug => $manifest) {
            $chain = $this->inheritanceChain((string) $slug);
            $rows[] = [
                'slug' => (string) $slug,
                'name' => (string) ($manifest['name'] ?? $slug),
                'is_stock' => $this->isStockSlug((string) $slug),
                'is_child_theme' => (bool) ($manifest['is_child_theme'] ?? false),
                'parent_theme' => (string) ($manifest['parent_theme'] ?? ''),
                'has_css' => is_file($this->themesRoot . '/' . $slug . '/css/style.css'),
                'has_wrapper' => is_file($this->themesRoot . '/' . $slug . '/tpl/wrapper.php'),
                'inheritance_chain' => implode(' -> ', $chain),
            ];
        }

        return $rows;
    }

    /**
     * Returns the stock-theme slug list reserved from uninstall/mutation flows.
     *
     * @return array<int, string>
     */
    public function stockSlugs(): array
    {
        return $this->stockSlugs;
    }

    /**
     * Returns whether the given theme slug is one of the protected stock themes.
     *
     * @param string $slug Theme slug to inspect.
     * @return bool True when the slug belongs to the stock-theme set.
     */
    public function isStockSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return in_array($normalized, $this->stockSlugs, true);
    }

    /**
     * Returns whether a theme slug satisfies Raven's public-theme slug contract.
     *
     * @param string $slug Candidate public-theme slug.
     * @return bool True when the slug is safe for filesystem/runtime use.
     */
    public function isSafeSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
    }

    /**
     * Derives a safe theme slug from one uploaded archive filename.
     *
     * @param string $archiveName Uploaded archive filename.
     * @return string|null Safe derived slug, or null when no valid slug can be derived.
     */
    public function slugFromArchiveFilename(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 80));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        // Reject empty/unsafe derived slugs before filesystem checks.
        if ($base === '' || !$this->isSafeSlug($base)) {
            return null;
        }

        return $base;
    }

    /**
     * Returns the next unused filesystem-safe slug derived from one base theme slug.
     *
     * @param string $baseSlug Requested base slug.
     * @param int $maxAttempts Maximum numbered-copy attempts before giving up.
     * @return string|null Available slug, or null when no safe free slug was found.
     */
    public function nextAvailableSlug(string $baseSlug, int $maxAttempts = 250): ?string
    {
        $normalizedBase = strtolower(trim($baseSlug));
        // Base slug must satisfy slug policy before copy suffixing.
        if (!$this->isSafeSlug($normalizedBase)) {
            return null;
        }

        $candidate = $normalizedBase;
        // Reuse base slug when target directory does not already exist.
        if (!file_exists($this->themesRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 64 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            // Ensure suffixing still yields a non-empty basename.
            if ($trimmedBase === '') {
                $trimmedBase = 'theme';
            }

            $candidate = $trimmedBase . $suffix;
            // Skip candidates that violate slug policy after suffix append.
            if (!$this->isSafeSlug($candidate)) {
                continue;
            }

            // Return first safe candidate whose directory path is unused.
            if (!file_exists($this->themesRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
