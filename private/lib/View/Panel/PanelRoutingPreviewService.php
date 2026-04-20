<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/PanelRoutingPreviewService.php
 * Shared routing-preview derivation helpers for panel diagnostics.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Core\Config;
use Raven\Lib\Parser\ModeParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared helpers for panel routing-preview derivations.
 */
final class PanelRoutingPreviewService
{
    private string $projectRoot;
    private InputSanitizer $input;
    private ThemeCatalogService $themeCatalog;

    /**
     * Stores the dependencies used to derive routing-preview values for the panel.
     *
     * @param string $projectRoot Project root used for fallback template checks.
     * @param InputSanitizer $input Shared sanitizer for route-preview slug normalization.
     * @param ThemeCatalogService $themeCatalog Shared theme catalog for inheritance-aware template checks.
     * @return void
     */
    public function __construct(string $projectRoot, InputSanitizer $input, ThemeCatalogService $themeCatalog)
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
     * @param string $channelSlug Raw channel slug for channel-scoped routes.
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
        $routeMode = ModeParser::normalizeRouteMode($channelPageRouteMode);
        $normalizedSlug = $this->input->slug($pageSlug);
        if (!ModeParser::usesPageId($routeMode) && ($normalizedSlug === null || $normalizedSlug === '')) {
            return '/';
        }

        $normalizedChannel = $this->input->slug($channelSlug);
        $routeSegment = ModeParser::buildRouteSegment(
            $this->input,
            (string) $normalizedSlug,
            $pageId,
            $publishedAt,
            $routeMode,
            $channelPageUrlSeparator,
            $contentSeparator
        );
        if ($routeSegment === '' && !ModeParser::usesPageId($routeMode)) {
            $routeSegment = $normalizedSlug;
        }

        if ($normalizedChannel === null || $normalizedChannel === '') {
            return '/' . $routeSegment;
        }

        return '/' . $normalizedChannel . '/' . $routeSegment;
    }

    /**
     * Picks the best landing-page slug per channel from one page row set.
     *
     * @param array<int, array<string, mixed>> $pagesForRouting
     * @return array<string, string> Map of channel slug to chosen landing-page slug.
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

    /**
     * Detects whether the active public-theme chain provides a channel index template.
     *
     * @param Config $config Runtime config reader used to resolve the active public theme.
     * @return bool True when the theme chain or core fallback supplies `channel/index.php`.
     */
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
     * Normalizes the reserved public-prefix list used by routing diagnostics.
     *
     * @param array<int, string> $routePrefixes
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
        foreach ($prefixes as $prefix) {
            $clean = strtolower(trim((string) $prefix));
            if ($clean !== '') {
                $normalized[$clean] = $clean;
            }
        }

        return array_values($normalized);
    }
}
