<?php

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Core\Config;
use Raven\Lib\Parser\FeedRouteParser;
use Raven\Lib\Transport\Request;
use Raven\Lib\Parser\UserDataParser;
use Raven\Lib\View\SiteContextBuilder;
use Raven\Lib\View\Panel\ThemeCatalogService;

/**
 * Shared site/public meta payload builder and social-image URL resolver.
 */
final class PublicMetaService
{
    private Request $requestContextResolver;
    private SiteContextBuilder $siteContextBuilder;
    private ThemeCatalogService $themeCatalogService;
    private UserDataParser $profileContactService;
    private FeedRouteParser $feedParser;

    public function __construct(
        Request $requestContextResolver,
        SiteContextBuilder $siteContextBuilder,
        ThemeCatalogService $themeCatalogService,
        UserDataParser $profileContactService,
        FeedRouteParser $feedParser
    ) {
        $this->requestContextResolver = $requestContextResolver;
        $this->siteContextBuilder = $siteContextBuilder;
        $this->themeCatalogService = $themeCatalogService;
        $this->profileContactService = $profileContactService;
        $this->feedParser = $feedParser;
    }

    /**
     * @return array<string, string>
     */
    public function siteData(Config $config): array
    {
        $publicTheme = $this->themeCatalogService->activeSlugFromConfig($config);
        $configuredProtocol = (string) $config->get('site.protocol', 'https');
        $configuredDomain = (string) $config->get('site.domain', 'localhost');
        $publicThemeActive = $this->themeCatalogService->cssSlug($publicTheme);

        $site = $this->siteContextBuilder->publicBase(
            $config,
            $this->requestContextResolver->siteBaseUrl($configuredDomain, $configuredProtocol),
            $this->requestContextResolver->currentRequestUrl($configuredDomain, $configuredProtocol),
            $publicTheme,
            $publicThemeActive,
            $this->resolvedConfiguredMetaImageUrl($config, $configuredDomain, $configuredProtocol)
        );

        return $this->withRootFeedUrls($site);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $site
     * @param callable(int): string|null $pagePreviewImageUrlResolver
     * @param callable(int): array<string, mixed>|null $authorById
     * @param array<int, array<string, mixed>> $profileOptions
     * @return array<string, string>
     */
    public function siteDataWithPageMeta(
        array $page,
        array $site,
        callable $pagePreviewImageUrlResolver,
        callable $authorById,
        array $profileOptions
    ): array {
        $site['twitter_creator'] = $this->resolvedTwitterCreatorForPage(
            $page,
            (string) ($site['twitter_creator'] ?? ''),
            $authorById,
            $profileOptions
        );

        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId < 1) {
            return $site;
        }

        $previewImageUrl = $this->absoluteMetaImageUrl(
            trim((string) (($pagePreviewImageUrlResolver)($pageId) ?? '')),
            (string) ($site['domain'] ?? 'localhost'),
            (string) ($site['protocol'] ?? 'https')
        );
        if ($previewImageUrl === '') {
            return $site;
        }

        $site['meta_image'] = $previewImageUrl;

        return $site;
    }

    /**
     * @param array<string, mixed> $taxonomy
     * @param array<string, string> $site
     * @return array<string, string>
     */
    public function siteDataWithTaxonomyMetaImage(array $taxonomy, array $site): array
    {
        $configuredDomain = (string) ($site['domain'] ?? 'localhost');

        $candidates = [
            trim((string) ($taxonomy['preview_image_lg_path'] ?? '')),
            trim((string) ($taxonomy['preview_image_path'] ?? '')),
            trim((string) ($taxonomy['preview_image_md_path'] ?? '')),
            trim((string) ($taxonomy['preview_image_sm_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_lg_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_md_path'] ?? '')),
            trim((string) ($taxonomy['cover_image_sm_path'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $resolved = $this->absoluteMetaImageUrl(
                $candidate,
                $configuredDomain,
                (string) ($site['protocol'] ?? 'https')
            );
            if ($resolved === '') {
                continue;
            }

            $site['meta_image'] = $resolved;
            return $site;
        }

        return $site;
    }

    public function absoluteMetaImageUrl(string $value, string $configuredDomain, string $configuredProtocol = ''): string
    {
        $value = trim(str_replace(["\r", "\n", "\0"], '', $value));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '//')) {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            return in_array($scheme, ['http', 'https'], true) ? $value : '';
        }

        $path = str_starts_with($value, '/') ? $value : ('/' . ltrim($value, '/'));
        $scheme = $this->requestContextResolver->resolveRequestScheme(null, $configuredProtocol);
        $host = $this->requestContextResolver->resolveRequestHost($configuredDomain);

        return $scheme . '://' . $host . $path;
    }

    private function resolvedConfiguredMetaImageUrl(
        Config $config,
        string $configuredDomain,
        string $configuredProtocol
    ): string {
        $configured = trim((string) $config->get('meta.image', ''));

        return $this->absoluteMetaImageUrl($configured, $configuredDomain, $configuredProtocol);
    }

    /**
     * @param array<string, string> $site
     * @return array<string, string>
     */
    private function withRootFeedUrls(array $site): array
    {
        $siteUrl = rtrim((string) ($site['url'] ?? ''), '/');
        $site['feed_rss_url'] = '';
        $site['feed_atom_url'] = '';

        if ($siteUrl === '' || !$this->feedParser->feedEnabled()) {
            return $site;
        }

        $rssRoute = $this->feedParser->rssFeedRoute();
        if ($rssRoute !== '') {
            $site['feed_rss_url'] = $siteUrl . '/' . ltrim($rssRoute, '/');
        }

        $atomRoute = $this->feedParser->atomFeedRoute();
        if ($atomRoute !== '') {
            $site['feed_atom_url'] = $siteUrl . '/' . ltrim($atomRoute, '/');
        }

        return $site;
    }

    /**
     * @param array<string, mixed> $page
     * @param callable(int): array<string, mixed>|null $authorById
     * @param array<int, array<string, mixed>> $profileOptions
     */
    private function resolvedTwitterCreatorForPage(
        array $page,
        string $fallback,
        callable $authorById,
        array $profileOptions
    ): string {
        $fallback = trim($fallback);
        $authorUserId = (int) ($page['author'] ?? 0);
        if ($authorUserId < 1) {
            return $fallback;
        }

        $author = $authorById($authorUserId);
        if (!is_array($author)) {
            return $fallback;
        }

        $profiles = is_array($author['contact'] ?? null) ? $author['contact'] : [];
        $creator = $this->profileContactService->twitterCreatorFromProfiles($profiles, $profileOptions);
        return $creator !== '' ? $creator : $fallback;
    }
}
