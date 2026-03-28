<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Core\Config;
use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared public-theme catalog and slug policy helper.
 */
final class ThemeCatalogService
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

    public function root(): string
    {
        return $this->themesRoot;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = PublicThemeRegistry::options($this->themesRoot);
        if ($options === []) {
            return ['raven' => 'Raven Basic'];
        }

        return $options;
    }

    public function activeSlugFromConfig(Config $config): string
    {
        $configured = strtolower($this->input->text((string) $config->get('site.theme', 'raven'), 80));
        $options = $this->options();

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
     * @return array<int, string>
     */
    public function inheritanceChain(string $themeSlug): array
    {
        $chain = PublicThemeRegistry::inheritanceChain($this->themesRoot, $themeSlug);
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }

    public function cssSlug(string $themeSlug): string
    {
        foreach ($this->inheritanceChain($themeSlug) as $candidateThemeSlug) {
            $cssPath = $this->themesRoot . '/' . $candidateThemeSlug . '/css/style.css';
            if (is_file($cssPath)) {
                return $candidateThemeSlug;
            }
        }

        return $themeSlug;
    }

    /**
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
        $manifests = PublicThemeRegistry::manifests($this->themesRoot);
        $rows = [];

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
     * @return array<int, string>
     */
    public function stockSlugs(): array
    {
        return $this->stockSlugs;
    }

    public function isStockSlug(string $slug): bool
    {
        $normalized = strtolower(trim($slug));
        return in_array($normalized, $this->stockSlugs, true);
    }

    public function isSafeSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
    }

    public function slugFromArchiveFilename(string $archiveName): ?string
    {
        $base = strtolower($this->input->text((string) pathinfo($archiveName, PATHINFO_FILENAME), 80));
        $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');

        if ($base === '' || !$this->isSafeSlug($base)) {
            return null;
        }

        return $base;
    }

    public function nextAvailableSlug(string $baseSlug, int $maxAttempts = 250): ?string
    {
        $normalizedBase = strtolower(trim($baseSlug));
        if (!$this->isSafeSlug($normalizedBase)) {
            return null;
        }

        $candidate = $normalizedBase;
        if (!file_exists($this->themesRoot . '/' . $candidate)) {
            return $candidate;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $suffix = $attempt === 1 ? '-copy' : '-copy-' . $attempt;
            $maxBaseLength = max(1, 64 - strlen($suffix));
            $trimmedBase = substr($normalizedBase, 0, $maxBaseLength);
            $trimmedBase = rtrim($trimmedBase, '-_');
            if ($trimmedBase === '') {
                $trimmedBase = 'theme';
            }

            $candidate = $trimmedBase . $suffix;
            if (!$this->isSafeSlug($candidate)) {
                continue;
            }

            if (!file_exists($this->themesRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
