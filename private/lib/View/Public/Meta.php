<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/Meta.php
 * Public site metadata payload builder and social-image URL resolver.
 * Docs: https://raven.lanterns.io
*/

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Core\Config;
use Raven\Core\Router\FeedPolicy;
use Raven\Lib\Transport\Request;
use Raven\Lib\Parser\UserProfileParser;

/**
 * Shared site/public meta payload builder and social-image URL resolver.
 */
final class Meta
{
    private Request $requestContextResolver;
    private ThemeCatalog $themeCatalogService;
    private UserProfileParser $profileContactService;
    private FeedPolicy $feedParser;

    /**
     * @param Request $requestContextResolver Shared request-context helper.
     * @param ThemeCatalog $themeCatalogService Public-theme catalog helper.
     * @param UserProfileParser $profileContactService Profile-contact helper for author social metadata.
     * @param FeedPolicy $feedParser Feed-route policy helper for root feed URLs.
     * @return void
     */
    public function __construct(
        Request $requestContextResolver,
        ThemeCatalog $themeCatalogService,
        UserProfileParser $profileContactService,
        FeedPolicy $feedParser
    ) {
        $this->requestContextResolver = $requestContextResolver;
        $this->themeCatalogService = $themeCatalogService;
        $this->profileContactService = $profileContactService;
        $this->feedParser = $feedParser;
    }

    /**
     * Builds the base site/meta payload for public theme templates.
     *
     * @param Config $config Runtime configuration reader.
     * @return array<string, string>
     */
    public function siteData(Config $config): array
    {
        $publicTheme = $this->themeCatalogService->activeSlugFromConfig($config);
        $configuredProtocol = (string) $config->get('site.protocol', 'https');
        $configuredDomain = (string) $config->get('site.domain', 'localhost');
        $publicThemeActive = $this->themeCatalogService->cssSlug($publicTheme);
        $siteUrl = $this->requestContextResolver->siteBaseUrl($configuredDomain, $configuredProtocol);

        $site = array_merge($this->baseSiteMetaData($config, $publicTheme, $publicThemeActive), [
            'url' => $siteUrl,
            'current_url' => $this->requestContextResolver->currentRequestUrl($configuredDomain, $configuredProtocol),
            'theme_url' => $this->themeUrl($siteUrl, $publicThemeActive),
            'meta_image' => $this->resolvedConfiguredMetaImageUrl($config, $configuredDomain, $configuredProtocol),
        ]);

        return $this->withRootFeedUrls($site);
    }

    /**
     * Applies page-specific meta overrides to one base site payload.
     *
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
     * Applies taxonomy preview/cover image overrides to one base site payload.
     *
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

    /**
     * Resolves a configured or stored image path into an absolute meta-image URL.
     *
     * @param string $value Candidate configured URL or local path.
     * @param string $configuredDomain Configured site domain for absolute-path expansion.
     * @param string $configuredProtocol Configured preferred scheme override.
     * @return string Absolute meta-image URL, or an empty string when unusable.
     */
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
     * Builds the shared config-owned meta keys used by every public template.
     *
     * @param Config $config Runtime configuration reader.
     * @param string $publicTheme Resolved active public-theme slug.
     * @param string $publicThemeActive Theme slug that owns the active stylesheet.
     * @return array<string, string> Shared public-theme wrapper payload keys.
     */
    private function baseSiteMetaData(Config $config, string $publicTheme, string $publicThemeActive): array
    {
        return [
            'name' => (string) $config->get('site.name', 'Raven CMS'),
            'protocol' => $this->normalizedConfiguredProtocol($config),
            'domain' => (string) $config->get('site.domain', 'localhost'),
            'panel_path' => (string) $config->get('panel.path', 'panel'),
            'apple_touch_icon' => trim((string) $config->get('meta.apple_touch_icon', '')),
            'robots' => trim((string) $config->get('meta.robots', 'index,follow')),
            'twitter_card' => trim((string) $config->get('meta.twitter.card', '')),
            'twitter_site' => trim((string) $config->get('meta.twitter.site', '')),
            'twitter_creator' => trim((string) $config->get('meta.twitter.creator', '')),
            'og_type' => trim((string) $config->get('meta.opengraph.type', 'website')),
            'og_locale' => trim((string) $config->get('meta.opengraph.locale', 'en_US')),
            'theme' => $publicTheme,
            'theme_active' => $publicThemeActive,
        ];
    }

    /**
     * Normalizes the configured site protocol to one HTTP scheme.
     *
     * @param Config $config Runtime configuration reader.
     * @return string `http` or `https`.
     */
    private function normalizedConfiguredProtocol(Config $config): string
    {
        $protocol = strtolower(trim((string) $config->get('site.protocol', 'https')));
        return in_array($protocol, ['http', 'https'], true) ? $protocol : 'https';
    }

    /**
     * Builds the public theme asset base URL from one resolved site URL.
     *
     * @param string $siteUrl Absolute site base URL.
     * @param string $themeCssSlug Theme slug that owns the active stylesheet.
     * @return string Absolute theme asset base URL.
     */
    private function themeUrl(string $siteUrl, string $themeCssSlug): string
    {
        $themeCssSlug = trim($themeCssSlug);
        if ($themeCssSlug === '') {
            $themeCssSlug = 'raven';
        }

        return rtrim($siteUrl, '/') . '/theme/' . rawurlencode($themeCssSlug);
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
