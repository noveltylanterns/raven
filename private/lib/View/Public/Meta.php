<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/Meta.php
 * Public site metadata payload builder and social-image URL resolver.
 * Docs: https://lanterns.io/raven
*/

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Raven\Core\Config;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\FeedPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Transport\Request;

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
     * @param string|null $themeOverride Optional channel theme override; null/inherit uses the global theme.
     * @return array<string, string>
     */
    public function siteData(Config $config, ?string $themeOverride = null): array
    {
        $publicTheme = $this->themeCatalogService->resolveOverrideSlug((string) ($themeOverride ?? 'inherit'), $config);
        $configuredProtocol = (string) $config->get('site.protocol', 'https');
        $configuredDomain = (string) $config->get('site.domain', 'localhost');
        $publicThemeActive = $this->themeCatalogService->cssSlug($publicTheme);
        $siteUrl = $this->requestContextResolver->siteBaseUrl($configuredDomain, $configuredProtocol);

        $site = array_merge($this->baseSiteMetaData($config, $publicTheme, $publicThemeActive), [
            'url' => $siteUrl,
            'current_url' => $this->requestContextResolver->currentRequestUrl($configuredDomain, $configuredProtocol),
            'theme_url' => $this->themeUrl($siteUrl, $publicThemeActive),
            'meta_image' => $this->resolvedConfiguredMetaImageUrl($config, $configuredDomain, $configuredProtocol),
            'apple_touch_icon' => $this->absoluteMetaImageUrl(
                (string) $config->get('meta.apple_touch_icon', ''),
                $configuredDomain,
                $configuredProtocol
            ),
        ]);

        return $this->withRootFeedUrls($site, $config);
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
        // Without a valid page id there is no preview-image override to resolve.
        if ($pageId < 1) {
            return $site;
        }

        $previewImageUrl = $this->absoluteMetaImageUrl(
            trim((string) (($pagePreviewImageUrlResolver)($pageId) ?? '')),
            (string) ($site['domain'] ?? 'localhost'),
            (string) ($site['protocol'] ?? 'https')
        );
        // Keep inherited meta image when page preview URL is absent or invalid.
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

        // Use the first non-empty candidate that resolves to a valid absolute URL.
        foreach ($candidates as $candidate) {
            // Empty candidate slots are ignored.
            if ($candidate === '') {
                continue;
            }

            $resolved = $this->absoluteMetaImageUrl(
                $candidate,
                $configuredDomain,
                (string) ($site['protocol'] ?? 'https')
            );
            // Invalid/unresolvable candidates are skipped until one succeeds.
            if ($resolved === '') {
                continue;
            }

            $site['meta_image'] = $resolved;
            return $site;
        }

        return $site;
    }

    /**
     * Resolves a configured or stored local image path into a same-origin meta-image URL.
     *
     * @param string $value Candidate local path.
     * @param string $configuredDomain Configured site domain for absolute-path expansion.
     * @param string $configuredProtocol Configured preferred scheme override.
     * @return string Same-origin meta-image URL, or an empty string when unusable.
     */
    public function absoluteMetaImageUrl(string $value, string $configuredDomain, string $configuredProtocol = ''): string
    {
        $value = trim(str_replace(["\r", "\n", "\0"], '', $value));
        // Empty strings cannot produce a usable absolute URL.
        if ($value === '') {
            return '';
        }

        // URI schemes and protocol-relative authorities are rejected so old or
        // imported config cannot create offsite browser image requests.
        if (
            preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $value) === 1
            || str_starts_with($value, '//')
        ) {
            return '';
        }

        $path = str_starts_with($value, '/') ? $value : ('/' . ltrim($value, '/'));
        $scheme = $this->requestContextResolver->resolveRequestScheme(null, $configuredProtocol);
        $host = $this->requestContextResolver->resolveRequestHost($configuredDomain);

        return $scheme . '://' . $host . $path;
    }

    /**
     * Resolves the configured global meta-image value into an absolute URL.
     *
     * @param Config $config Runtime config reader.
     * @param string $configuredDomain Configured site domain.
     * @param string $configuredProtocol Configured preferred protocol.
     * @return string Absolute URL, or empty string when unset/invalid.
     */
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
        // Fall back to canonical theme asset folder when slug is blank.
        if ($themeCssSlug === '') {
            $themeCssSlug = 'raven';
        }

        return rtrim($siteUrl, '/') . '/theme/' . rawurlencode($themeCssSlug);
    }

    /**
     * @param array<string, string> $site
     * @return array<string, string>
     */
    private function withRootFeedUrls(array $site, Config $config): array
    {
        $siteUrl = rtrim((string) ($site['url'] ?? ''), '/');
        $site['feed_rss_url'] = '';
        $site['feed_atom_url'] = '';

        // Feed links are omitted when site URL is missing or feeds are disabled.
        if ($siteUrl === '' || !$this->feedParser->feedEnabled()) {
            return $site;
        }

        $rssRoute = $this->feedParser->rssRoute();
        // Emit RSS URL only when feed policy exposes an RSS route.
        if ($rssRoute !== '') {
            $site['feed_rss_url'] = $siteUrl . PagePolicy::canonicalPath(
                '/' . ltrim($rssRoute, '/'),
                ChannelPolicy::siteRoutingUsesTrailingSlash($config)
            );
        }

        $atomRoute = $this->feedParser->atomRoute();
        // Emit Atom URL only when feed policy exposes an Atom route.
        if ($atomRoute !== '') {
            $site['feed_atom_url'] = $siteUrl . PagePolicy::canonicalPath(
                '/' . ltrim($atomRoute, '/'),
                ChannelPolicy::siteRoutingUsesTrailingSlash($config)
            );
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
        // Without a valid author id we cannot resolve profile-owned social metadata.
        if ($authorUserId < 1) {
            return $fallback;
        }

        $author = $authorById($authorUserId);
        // Missing author rows preserve configured fallback creator value.
        if (!is_array($author)) {
            return $fallback;
        }

        $profiles = is_array($author['contact'] ?? null) ? $author['contact'] : [];
        $creator = $this->profileContactService->twitterCreatorFromProfiles($profiles, $profileOptions);
        return $creator !== '' ? $creator : $fallback;
    }
}
