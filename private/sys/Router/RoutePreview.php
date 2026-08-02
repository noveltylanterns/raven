<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/RoutePreview.php
 * Shared routing-preview derivation helpers for panel diagnostics.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Public\ThemeCatalog;

/**
 * Shared helpers for panel routing-preview derivations.
 */
final class RoutePreview
{
    private string $projectRoot;
    private InputSanitizer $input;
    private ThemeCatalog $themeCatalog;

    /**
     * Stores the dependencies used to derive routing-preview values for the panel.
     *
     * @param string $projectRoot Project root used for fallback template checks.
     * @param InputSanitizer $input Shared sanitizer for route-preview slug normalization.
     * @param ThemeCatalog $themeCatalog Shared theme catalog for inheritance-aware template checks.
     * @return void
     */
    public function __construct(string $projectRoot, InputSanitizer $input, ThemeCatalog $themeCatalog)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->input = $input;
        $this->themeCatalog = $themeCatalog;
    }

    /**
     * Builds the public-facing preview path for one page under the current routing rules.
     *
     * @param string $pageSlug Raw page slug.
     * @param int $pageId Page id used by id-bearing route modes.
     * @param string $channelSlug Parent-aware channel path for channel-scoped routes.
     * @param string $publishedAt Published timestamp string used by dated route modes.
     * @param string $channelPageRouteMode Configured route mode for channel pages.
     * @param string $channelPageUrlSeparator Configured separator for channel page routes.
     * @param string $contentSeparator Fallback content separator when route policy needs one.
     * @return string Root-relative preview path for the current routing settings.
     */
    public function routingPublicPathForPage(
        string $pageSlug,
        int $pageId,
        string $channelSlug,
        string $publishedAt,
        string $channelPageRouteMode,
        string $channelPageUrlSeparator,
        string $contentSeparator = '-'
    ): string {
        $routeMode = ChannelPolicy::normalizeRouteMode($channelPageRouteMode);
        $normalizedSlug = $this->input->slug($pageSlug);
        // Slug-based modes cannot build previews when slug normalization fails.
        if (!ChannelPolicy::usesPageId($routeMode) && ($normalizedSlug === null || $normalizedSlug === '')) {
            return '/';
        }

        $normalizedChannel = $this->normalizeChannelPath($channelSlug);
        $routeSegment = PagePolicy::buildRouteSegment(
            $this->input,
            (string) $normalizedSlug,
            $pageId,
            $publishedAt,
            $routeMode,
            $channelPageUrlSeparator,
            $contentSeparator
        );
        // Slug-mode previews fall back to normalized slug when segment builder returns empty.
        if ($routeSegment === '' && !ChannelPolicy::usesPageId($routeMode)) {
            $routeSegment = $normalizedSlug;
        }

        // Root-scope pages omit the channel segment from their preview path.
        if ($normalizedChannel === null || $normalizedChannel === '') {
            return '/' . $routeSegment;
        }

        return '/' . $normalizedChannel . '/' . $routeSegment;
    }

    /**
     * Normalizes each segment of a parent-aware channel path independently.
     *
     * @param string $channelPath Raw slash-separated channel path.
     * @return string|null Normalized path, or null when any segment is invalid.
     */
    private function normalizeChannelPath(string $channelPath): ?string
    {
        $trimmedPath = trim($channelPath, '/');
        if ($trimmedPath === '') {
            return null;
        }

        $segments = explode('/', $trimmedPath);
        $normalizedSegments = [];
        foreach ($segments as $segment) {
            $normalized = $this->input->slug($segment);
            if ($normalized === null || $normalized === '') {
                return null;
            }

            $normalizedSegments[] = $normalized;
        }

        return implode('/', $normalizedSegments);
    }

    /**
     * Picks the best landing-page slug per channel from one page row set.
     *
     * @param array<int, array<string, mixed>> $pagesForRouting Full page row set to scan for landing candidates.
     * @return array<string, string> Map of channel slug to chosen landing-page slug.
     */
    public function channelLandingMapFromPages(array $pagesForRouting): array
    {
        /** @var array<string, array{slug: string, priority: int, published_ts: int}> $best */
        $best = [];

        // Scan candidate pages and keep best landing slug per channel.
        foreach ($pagesForRouting as $page) {
            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            // Landing maps apply only to channel-scoped pages.
            if ($channelSlug === '') {
                continue;
            }

            // Unpublished pages are never valid channel landing targets.
            if (($page['status'] ?? '') !== 'published') {
                continue;
            }

            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $priority = match ($pageSlug) {
                'home' => 0,
                'index' => 1,
                default => null,
            };
            // Only home/index slugs participate in landing-page selection.
            if ($priority === null) {
                continue;
            }

            $createdAt = trim((string) ($page['created'] ?? ''));
            $publishedTs = $createdAt !== '' ? (int) strtotime($createdAt) : 0;
            // Clamp failed/negative timestamps to zero for deterministic comparisons.
            if ($publishedTs < 0) {
                $publishedTs = 0;
            }

            $candidate = [
                'slug' => $pageSlug,
                'priority' => $priority,
                'published_ts' => $publishedTs,
            ];

            // First candidate for a channel becomes the baseline.
            if (!isset($best[$channelSlug])) {
                $best[$channelSlug] = $candidate;
                continue;
            }

            $current = $best[$channelSlug];
            // Prefer higher-priority slug, then newer publish timestamp as tie-breaker.
            if (
                $candidate['priority'] < $current['priority']
                || (
                    $candidate['priority'] === $current['priority']
                    && $candidate['published_ts'] > $current['published_ts']
                )
            ) {
                $best[$channelSlug] = $candidate;
            }
        }

        $result = [];
        // Flatten the candidate structs to channel=>slug output map.
        foreach ($best as $channelSlug => $candidate) {
            $result[$channelSlug] = (string) ($candidate['slug'] ?? '');
        }

        return $result;
    }

    /**
     * Detects whether the active public-theme chain provides a channel index template.
     *
     * @param Config $config Runtime config reader used to resolve the active public theme.
     * @return bool True when the theme chain or core fallback supplies `channel/index.php`.
     */
    public function channelIndexTemplateExists(Config $config): bool
    {
        $themeSlug = $this->themeCatalog->activeSlugFromConfig($config);
        // Walk theme inheritance from active theme toward parent fallbacks.
        foreach ($this->themeCatalog->inheritanceChain($themeSlug) as $candidateThemeSlug) {
            $candidate = $this->themeCatalog->root() . '/' . $candidateThemeSlug . '/tpl/channel/index.php';
            // First template hit is enough to confirm channel index availability.
            if (is_file($candidate)) {
                return true;
            }
        }

        return is_file($this->projectRoot . '/private/tpl/public/channel/index.php');
    }

    /**
     * Normalizes the reserved public-prefix list used by routing diagnostics.
     *
     * @param string $panelPath Panel path prefix to exclude from public routing.
     * @param array<int, string> $routePrefixes Additional reserved prefix strings to include.
     * @return array<int, string> Deduplicated lowercase reserved prefixes.
     */
    public function reservedPublicPrefixes(string $panelPath, array $routePrefixes = []): array
    {
        $prefixes = [
            trim($panelPath, '/'),
            'panel',
            'boot',
            'mce',
            'theme',
            ...$routePrefixes,
        ];

        $normalized = [];
        // Normalize reserved prefixes to lowercase unique slugs.
        foreach ($prefixes as $prefix) {
            $clean = strtolower(trim((string) $prefix));
            // Drop empty values so callers receive only actionable reserved paths.
            if ($clean !== '') {
                $normalized[$clean] = $clean;
            }
        }

        return array_values($normalized);
    }
}
