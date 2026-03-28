<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\ThemeCatalogService;

/**
 * Shared helpers for panel routing-preview derivations.
 */
final class PanelRoutingPreviewService
{
    private string $projectRoot;
    private InputSanitizer $input;
    private ThemeCatalogService $themeCatalog;

    public function __construct(string $projectRoot, InputSanitizer $input, ThemeCatalogService $themeCatalog)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->input = $input;
        $this->themeCatalog = $themeCatalog;
    }

    public function routingPublicPathForPage(
        string $pageSlug,
        int $pageId,
        string $channelSlug,
        string $publishedAt,
        string $channelPageRouteMode,
        string $channelPageUrlSeparator,
        string $contentSeparator = '-'
    ): string {
        $routeMode = ChannelRoutePolicy::normalizeRouteMode($channelPageRouteMode);
        $normalizedSlug = $this->input->slug($pageSlug);
        if (!ChannelRoutePolicy::usesPageId($routeMode) && ($normalizedSlug === null || $normalizedSlug === '')) {
            return '/';
        }

        $normalizedChannel = $this->input->slug($channelSlug);
        $routeSegment = ChannelRoutePolicy::buildRouteSegment(
            $this->input,
            (string) $normalizedSlug,
            $pageId,
            $publishedAt,
            $routeMode,
            $channelPageUrlSeparator,
            $contentSeparator
        );
        if ($routeSegment === '' && !ChannelRoutePolicy::usesPageId($routeMode)) {
            $routeSegment = $normalizedSlug;
        }

        if ($normalizedChannel === null || $normalizedChannel === '') {
            return '/' . $routeSegment;
        }

        return '/' . $normalizedChannel . '/' . $routeSegment;
    }

    /**
     * @param array<int, array<string, mixed>> $pagesForRouting
     * @return array<string, string>
     */
    public function channelLandingMapFromPages(array $pagesForRouting): array
    {
        /** @var array<string, array{slug: string, priority: int, published_ts: int}> $best */
        $best = [];

        foreach ($pagesForRouting as $page) {
            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === '') {
                continue;
            }

            if (($page['status'] ?? '') !== 'published') {
                continue;
            }

            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $priority = match ($pageSlug) {
                'home' => 0,
                'index' => 1,
                default => null,
            };
            if ($priority === null) {
                continue;
            }

            $createdAt = trim((string) ($page['created'] ?? ''));
            $publishedTs = $createdAt !== '' ? (int) strtotime($createdAt) : 0;
            if ($publishedTs < 0) {
                $publishedTs = 0;
            }

            $candidate = [
                'slug' => $pageSlug,
                'priority' => $priority,
                'published_ts' => $publishedTs,
            ];

            if (!isset($best[$channelSlug])) {
                $best[$channelSlug] = $candidate;
                continue;
            }

            $current = $best[$channelSlug];
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
        foreach ($best as $channelSlug => $candidate) {
            $result[$channelSlug] = (string) ($candidate['slug'] ?? '');
        }

        return $result;
    }

    public function channelIndexTemplateExists(Config $config): bool
    {
        $themeSlug = $this->themeCatalog->activeSlugFromConfig($config);
        foreach ($this->themeCatalog->inheritanceChain($themeSlug) as $candidateThemeSlug) {
            $candidate = $this->themeCatalog->root() . '/' . $candidateThemeSlug . '/tpl/channel/index.php';
            if (is_file($candidate)) {
                return true;
            }
        }

        return is_file($this->projectRoot . '/private/tpl/channel/index.php');
    }

    /**
     * @param array<int, string> $routePrefixes
     * @return array<int, string>
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
        foreach ($prefixes as $prefix) {
            $clean = strtolower(trim((string) $prefix));
            if ($clean !== '') {
                $normalized[$clean] = $clean;
            }
        }

        return array_values($normalized);
    }
}
