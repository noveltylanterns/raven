<?php

declare(strict_types=1);

namespace Raven\Lib\Site;

use Raven\Core\Config;
use Raven\Lib\Http\RequestContextResolver;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\View\ThemeCatalogService;

/**
 * Shared site/public meta payload builder and social-image URL resolver.
 */
final class PublicMetaService
{
    private RequestContextResolver $requestContextResolver;
    private SiteContextBuilder $siteContextBuilder;
    private ThemeCatalogService $themeCatalogService;
    private ProfileContactService $profileContactService;

    public function __construct(
        RequestContextResolver $requestContextResolver,
        SiteContextBuilder $siteContextBuilder,
        ThemeCatalogService $themeCatalogService,
        ProfileContactService $profileContactService
    ) {
        $this->requestContextResolver = $requestContextResolver;
        $this->siteContextBuilder = $siteContextBuilder;
        $this->themeCatalogService = $themeCatalogService;
        $this->profileContactService = $profileContactService;
    }

    /**
     * @return array<string, string>
     */
    public function siteData(Config $config): array
    {
        $publicTheme = $this->themeCatalogService->activeSlugFromConfig($config);
        $configuredProtocol = (string) $config->get('site.protocol', 'https');
        $configuredDomain = (string) $config->get('site.domain', 'localhost');
        $publicThemeCss = $this->themeCatalogService->cssSlug($publicTheme);

        return $this->siteContextBuilder->publicBase(
            $config,
            $this->requestContextResolver->siteBaseUrl($configuredDomain, $configuredProtocol),
            $this->requestContextResolver->currentRequestUrl($configuredDomain, $configuredProtocol),
            $publicTheme,
            $publicThemeCss,
            $this->resolvedConfiguredMetaImageUrl($config, $configuredDomain, $configuredProtocol)
        );
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
        $configured = trim((string) $config->get('meta.opengraph.image', ''));
        if ($configured === '') {
            $configured = trim((string) $config->get('meta.twitter.image', ''));
        }

        return $this->absoluteMetaImageUrl($configured, $configuredDomain, $configuredProtocol);
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
        $authorUserId = (int) ($page['author_user_id'] ?? 0);
        if ($authorUserId < 1) {
            return $fallback;
        }

        $author = $authorById($authorUserId);
        if (!is_array($author)) {
            return $fallback;
        }

        $profiles = is_array($author['contact_profiles'] ?? null) ? $author['contact_profiles'] : [];
        $creator = $this->profileContactService->twitterCreatorFromProfiles($profiles, $profileOptions);
        return $creator !== '' ? $creator : $fallback;
    }
}
