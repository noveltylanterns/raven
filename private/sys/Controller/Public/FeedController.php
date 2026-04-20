<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/FeedController.php
 * Split public feed/taxonomy controller for feed and taxonomy-list routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\TaxonomyLookupRepository;
use Raven\Core\Routing\Public\PublicChannelPageRouteService;
use Raven\Lib\Parser\CategoryRouteParser;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\PageDataParser;
use Raven\Lib\Parser\TagRouteParser;
use Raven\Lib\View\Public\PublicTemplateDecorator;
use Raven\Lib\View\Public\PublicTemplatePipeline;
use Raven\Lib\View\Public\PublicTemplateResolver;
use Raven\Lib\View\Panel\ThemeCatalogService;

/**
 * Handles split public feed and taxonomy-list routes.
 */
final class FeedController
{
    private SharedController $context;
    private ChannelRepository $channelRepo;
    private PageDataParser $pageParser;
    private TaxonomyLookupRepository $taxonomyLookupRepo;
    private PublicTemplateDecorator $publicTemplateDecorator;
    private PublicChannelPageRouteService $publicChannelPageRouteService;
    private ThemeCatalogService $themeCatalogService;
    private ?PublicTemplateResolver $publicTemplateResolver = null;
    private ?PublicTemplatePipeline $publicTemplatePipeline = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param ChannelRepository $channelRepo Channel repository for feed/channel label lookups.
     * @param PageRepository $pageRepo Page repository for feed and taxonomy listing rows.
     * @param TaxonomyLookupRepository $taxonomyLookupRepo Taxonomy lookup repository for category/tag resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        ChannelRepository $channelRepo,
        PageRepository $pageRepo,
        TaxonomyLookupRepository $taxonomyLookupRepo
    ) {
        $this->context = $context;
        $this->channelRepo = $channelRepo;
        $this->pageParser = new PageDataParser($context->input(), $pageRepo);
        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        $this->publicTemplateDecorator = new PublicTemplateDecorator(
            $context->config(),
            $context->input(),
            dirname(__DIR__, 4)
        );
        $this->publicChannelPageRouteService = new PublicChannelPageRouteService($context->input());
        $this->themeCatalogService = new ThemeCatalogService(
            dirname(__DIR__, 4) . '/public/theme',
            $context->input(),
            ['raven']
        );
    }

    /**
     * Renders category listing route `/{category_prefix}/{category_slug}/{page?}`.
     *
     * @param string $categorySlug Normalized category slug.
     * @param int $pageNumber Requested page number.
     * @return void
     */
    public function category(string $categorySlug, int $pageNumber = 1): void
    {
        $categoryPrefix = CategoryRouteParser::categoryRoutePrefix($this->context->config(), $this->context->input());
        if ($categoryPrefix === '') {
            $this->context->notFound();
            return;
        }

        $category = $this->taxonomyLookupRepo->findCategoryBySlug($categorySlug);
        if ($category === null) {
            $this->context->notFound();
            return;
        }

        $perPage = max(1, (int) $this->context->config()->get('category.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageParser->listPageByCategorySlug($categorySlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($total > 0 && $pageNumber > $totalPages) {
            $this->context->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pages = $this->decoratePageListPublicPaths($pages);
        $pages = $this->publicTemplateDecorator->decoratePageListForTemplate($pages);
        $pagination = $this->publicTemplateDecorator->decoratePaginationForTemplate([
            'current' => $pageNumber,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_path' => '/' . $categoryPrefix . '/' . rawurlencode($categorySlug),
        ]);
        $categoryTemplate = $this->publicTemplatePipeline()->resolveCategoryTemplateNameForThemeChain(
            $categorySlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        $this->context->renderPublic($categoryTemplate, [
            'site' => $this->context->siteDataWithTaxonomyMetaImage($category),
            'category' => $category,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Renders tag listing route `/{tag_prefix}/{tag_slug}/{page?}`.
     *
     * @param string $tagSlug Normalized tag slug.
     * @param int $pageNumber Requested page number.
     * @return void
     */
    public function tag(string $tagSlug, int $pageNumber = 1): void
    {
        $tagPrefix = TagRouteParser::tagRoutePrefix($this->context->config(), $this->context->input());
        if ($tagPrefix === '') {
            $this->context->notFound();
            return;
        }

        $tag = $this->taxonomyLookupRepo->findTagBySlug($tagSlug);
        if ($tag === null) {
            $this->context->notFound();
            return;
        }

        $perPage = max(1, (int) $this->context->config()->get('tag.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageParser->listPageByTagSlug($tagSlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($total > 0 && $pageNumber > $totalPages) {
            $this->context->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pages = $this->decoratePageListPublicPaths($pages);
        $pages = $this->publicTemplateDecorator->decoratePageListForTemplate($pages);
        $pagination = $this->publicTemplateDecorator->decoratePaginationForTemplate([
            'current' => $pageNumber,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_path' => '/' . $tagPrefix . '/' . rawurlencode($tagSlug),
        ]);
        $tagTemplate = $this->publicTemplatePipeline()->resolveTagTemplateNameForThemeChain(
            $tagSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        $this->context->renderPublic($tagTemplate, [
            'site' => $this->context->siteDataWithTaxonomyMetaImage($tag),
            'tag' => $tag,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Renders RSS feed route `/{feed.rss}` when feeds are enabled.
     *
     * @param string|null $channelSlug Optional channel slug for channel-scoped feeds.
     * @return void
     */
    public function rssFeed(?string $channelSlug = null): void
    {
        $this->renderFeed('rss', $channelSlug);
    }

    /**
     * Renders RSS category feed route `/{feed.rss}/{category.prefix}/{category_slug}`.
     *
     * @param string $categorySlug Normalized category slug.
     * @return void
     */
    public function rssCategoryFeed(string $categorySlug): void
    {
        $this->renderTaxonomyFeed('rss', 'category', $categorySlug);
    }

    /**
     * Renders RSS tag feed route `/{feed.rss}/{tag.prefix}/{tag_slug}`.
     *
     * @param string $tagSlug Normalized tag slug.
     * @return void
     */
    public function rssTagFeed(string $tagSlug): void
    {
        $this->renderTaxonomyFeed('rss', 'tag', $tagSlug);
    }

    /**
     * Renders Atom feed route `/{feed.atom}` when feeds are enabled.
     *
     * @param string|null $channelSlug Optional channel slug for channel-scoped feeds.
     * @return void
     */
    public function atomFeed(?string $channelSlug = null): void
    {
        $this->renderFeed('atom', $channelSlug);
    }

    /**
     * Renders Atom category feed route `/{feed.atom}/{category.prefix}/{category_slug}`.
     *
     * @param string $categorySlug Normalized category slug.
     * @return void
     */
    public function atomCategoryFeed(string $categorySlug): void
    {
        $this->renderTaxonomyFeed('atom', 'category', $categorySlug);
    }

    /**
     * Renders Atom tag feed route `/{feed.atom}/{tag.prefix}/{tag_slug}`.
     *
     * @param string $tagSlug Normalized tag slug.
     * @return void
     */
    public function atomTagFeed(string $tagSlug): void
    {
        $this->renderTaxonomyFeed('atom', 'tag', $tagSlug);
    }

    /**
     * Renders one configured feed response as XML without the HTML wrapper.
     *
     * @param string $format Feed format key (`rss` or `atom`).
     * @param string|null $channelSlug Optional channel slug for channel-scoped feeds.
     * @return void
     */
    private function renderFeed(string $format, ?string $channelSlug = null): void
    {
        $feedParser = $this->context->feedParser();
        if (!$feedParser->feedEnabled()) {
            $this->context->notFound();
            return;
        }

        $routeSegment = $format === 'atom' ? $feedParser->atomFeedRoute() : $feedParser->rssFeedRoute();
        if ($routeSegment === '') {
            $this->context->notFound();
            return;
        }

        $site = $this->context->siteData();
        $feedChannelSlug = '';
        $configuredFeedChannels = $feedParser->feedChannels();
        $scopeLabel = '';
        $scopeType = 'global';
        $scopeSlug = '';
        $pages = [];

        if ($channelSlug !== null) {
            $normalizedChannelSlug = strtolower(trim($channelSlug));
            if ($normalizedChannelSlug === '') {
                $this->context->notFound();
                return;
            }

            $channel = $this->channelRepo->findBySlug($normalizedChannelSlug);
            if (!is_array($channel) || !$this->channelFeedEnabled($channel)) {
                $this->context->notFound();
                return;
            }

            $feedChannelSlug = $normalizedChannelSlug;
            $scopeLabel = $this->feedChannelLabel($feedChannelSlug);
            $scopeType = 'channel';
            $scopeSlug = $feedChannelSlug;
            $pages = $this->pageParser->listRecentPublished($feedParser->feedItems(), $feedChannelSlug);
        } else {
            if (in_array('all', $configuredFeedChannels, true)) {
                $pages = $this->pageParser->listRecentPublished($feedParser->feedItems(), null);
            } else {
                $selectedFeedChannels = array_values(array_filter(
                    $configuredFeedChannels,
                    static fn (string $configuredChannel): bool => $configuredChannel !== ''
                ));
                if (count($selectedFeedChannels) === 1) {
                    $feedChannelSlug = $selectedFeedChannels[0];
                    $scopeLabel = $this->feedChannelLabel($feedChannelSlug);
                    $scopeType = 'channel';
                    $scopeSlug = $feedChannelSlug;
                } elseif ($selectedFeedChannels !== []) {
                    $scopeLabel = 'Selected Channels';
                    $scopeType = 'channels';
                }

                $pages = $this->pageParser->listRecentPublishedForChannels(
                    $feedParser->feedItems(),
                    $selectedFeedChannels
                );
            }

            if ($feedChannelSlug !== '') {
                $scopeType = 'channel';
            }
        }

        $feedPayload = $this->buildFeedPayload(
            $format,
            $this->buildFeedRoutePath(
                $routeSegment,
                $channelSlug !== null && $feedChannelSlug !== '' ? [$feedChannelSlug] : []
            ),
            $scopeLabel,
            $site,
            $pages,
            $scopeType,
            $scopeSlug
        );

        header('Content-Type: ' . ($format === 'atom' ? 'application/atom+xml' : 'application/rss+xml') . '; charset=UTF-8');
        $this->context->renderPublic('feeds/' . $format, [
            'site' => $site,
            'feed' => $feedPayload,
            'pages' => $feedPayload['items'],
        ], null);
    }

    /**
     * Renders one taxonomy feed response for categories or tags.
     *
     * @param string $format Feed format key (`rss` or `atom`).
     * @param string $taxonomyType Taxonomy type key (`category` or `tag`).
     * @param string $taxonomySlug Raw taxonomy slug.
     * @return void
     */
    private function renderTaxonomyFeed(string $format, string $taxonomyType, string $taxonomySlug): void
    {
        $feedParser = $this->context->feedParser();
        if (!$feedParser->feedEnabled()) {
            $this->context->notFound();
            return;
        }

        $routeSegment = $format === 'atom' ? $feedParser->atomFeedRoute() : $feedParser->rssFeedRoute();
        if ($routeSegment === '') {
            $this->context->notFound();
            return;
        }

        $normalizedSlug = strtolower(trim($taxonomySlug));
        if ($normalizedSlug === '') {
            $this->context->notFound();
            return;
        }

        $site = $this->context->siteData();
        $scopeLabel = '';
        $routeSuffix = [];
        $pages = [];

        if ($taxonomyType === 'category') {
            $categoryPrefix = CategoryRouteParser::categoryRoutePrefix($this->context->config(), $this->context->input());
            if ($categoryPrefix === '') {
                $this->context->notFound();
                return;
            }

            $category = $this->taxonomyLookupRepo->findCategoryBySlug($normalizedSlug);
            if (!is_array($category)) {
                $this->context->notFound();
                return;
            }

            $pageResult = $this->pageParser->listPageByCategorySlug($normalizedSlug, $feedParser->feedItems(), 0);
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $scopeLabel = $this->taxonomyFeedLabel($category, $normalizedSlug);
            $routeSuffix = [$categoryPrefix, $normalizedSlug];
        } elseif ($taxonomyType === 'tag') {
            $tagPrefix = TagRouteParser::tagRoutePrefix($this->context->config(), $this->context->input());
            if ($tagPrefix === '') {
                $this->context->notFound();
                return;
            }

            $tag = $this->taxonomyLookupRepo->findTagBySlug($normalizedSlug);
            if (!is_array($tag)) {
                $this->context->notFound();
                return;
            }

            $pageResult = $this->pageParser->listPageByTagSlug($normalizedSlug, $feedParser->feedItems(), 0);
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $scopeLabel = $this->taxonomyFeedLabel($tag, $normalizedSlug);
            $routeSuffix = [$tagPrefix, $normalizedSlug];
        } else {
            $this->context->notFound();
            return;
        }

        $feedPayload = $this->buildFeedPayload(
            $format,
            $this->buildFeedRoutePath($routeSegment, $routeSuffix),
            $scopeLabel,
            $site,
            $pages,
            $taxonomyType,
            $normalizedSlug
        );

        header('Content-Type: ' . ($format === 'atom' ? 'application/atom+xml' : 'application/rss+xml') . '; charset=UTF-8');
        $this->context->renderPublic('feeds/' . $format, [
            'site' => $site,
            'feed' => $feedPayload,
            'pages' => $feedPayload['items'],
        ], null);
    }

    /**
     * Builds one feed payload ready for XML templates.
     *
     * @param string $format Feed format key.
     * @param string $routePath Relative route path for the feed endpoint.
     * @param string $scopeLabel Human-readable scope label.
     * @param array<string, string> $site Public site metadata payload.
     * @param array<int, array<string, mixed>> $pages Feed source page rows.
     * @param string $scopeType Feed scope type key.
     * @param string $scopeSlug Feed scope slug.
     * @return array<string, mixed> Feed payload for XML templates.
     */
    private function buildFeedPayload(
        string $format,
        string $routePath,
        string $scopeLabel,
        array $site,
        array $pages,
        string $scopeType = 'global',
        string $scopeSlug = ''
    ): array {
        $feedUrl = trim((string) ($site['current_url'] ?? ''));
        if ($feedUrl === '') {
            $feedUrl = rtrim((string) ($site['url'] ?? ''), '/') . '/' . ltrim($routePath, '/');
        }

        $siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
        if ($siteName === '') {
            $siteName = 'Raven CMS';
        }

        $formatLabel = strtoupper($format);
        $title = $siteName . ' ' . $formatLabel . ' Feed';
        $description = 'Latest pages from ' . $siteName . '.';
        if ($scopeLabel !== '') {
            $title = $siteName . ' ' . $formatLabel . ' Feed (' . $scopeLabel . ')';
            $description = 'Latest pages from ' . $scopeLabel . ' on ' . $siteName . '.';
        }

        $items = $this->decorateFeedPages($pages, $site);
        $updatedTimestamp = time();
        if ($items !== []) {
            $updatedTimestamp = (int) ($items[0]['timestamp'] ?? $updatedTimestamp);
        }

        return [
            'format' => $format,
            'title' => $title,
            'description' => $description,
            'url' => $feedUrl,
            'site_url' => (string) ($site['url'] ?? ''),
            'channel_slug' => $scopeType === 'channel' ? $scopeSlug : '',
            'channel_label' => $scopeType === 'channel' ? $scopeLabel : '',
            'scope_type' => $scopeType,
            'scope_slug' => $scopeSlug,
            'scope_label' => $scopeLabel,
            'updated_rss' => gmdate(DATE_RSS, $updatedTimestamp),
            'updated_atom' => gmdate(DATE_ATOM, $updatedTimestamp),
            'items' => $items,
        ];
    }

    /**
     * Decorates feed page rows with absolute URLs and date fields.
     *
     * @param array<int, array<string, mixed>> $pages Feed source page rows.
     * @param array<string, string> $site Public site metadata payload.
     * @return array<int, array<string, mixed>> Feed template rows.
     */
    private function decorateFeedPages(array $pages, array $site): array
    {
        $pages = $this->decoratePageListPublicPaths($pages);
        $siteUrl = rtrim((string) ($site['url'] ?? ''), '/');
        $result = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $path = trim((string) ($page['url'] ?? ''));
            if ($path === '') {
                $path = '/';
            }
            if (!str_starts_with($path, '/')) {
                $path = '/' . ltrim($path, '/');
            }

            $absoluteUrl = $siteUrl !== '' ? $siteUrl . $path : $path;
            $title = trim((string) ($page['title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($page['slug'] ?? ''));
            }
            if ($title === '') {
                $title = 'Untitled';
            }

            $description = trim((string) ($page['description'] ?? ''));
            $createdAt = trim((string) ($page['created'] ?? ''));
            $timestamp = strtotime($createdAt);
            if ($timestamp === false || $timestamp < 1) {
                $timestamp = time();
            }

            $page['feed_title'] = $title;
            $page['feed_description'] = $description;
            $page['absolute_url'] = $absoluteUrl;
            $page['rss_published_at'] = gmdate(DATE_RSS, $timestamp);
            $page['atom_published_at'] = gmdate(DATE_ATOM, $timestamp);
            $page['timestamp'] = $timestamp;
            $result[] = $page;
        }

        return $result;
    }

    /**
     * Returns one human-readable feed channel label.
     *
     * @param string $channelSlug Normalized channel slug.
     * @return string Human-readable channel label.
     */
    private function feedChannelLabel(string $channelSlug): string
    {
        $normalized = strtolower(trim($channelSlug));
        if ($normalized === '') {
            return 'All Channels';
        }

        if ($normalized === 'root') {
            return 'Root';
        }

        $channel = $this->channelRepo->findBySlug($normalized);
        if (!is_array($channel)) {
            return $normalized;
        }

        $name = trim((string) ($channel['name'] ?? ''));
        return $name !== '' ? $name : $normalized;
    }

    /**
     * Returns whether one channel explicitly allows channel-specific feed routes.
     *
     * @param array<string, mixed> $channel Channel row.
     * @return bool True when the channel feed toggle is enabled.
     */
    private function channelFeedEnabled(array $channel): bool
    {
        return (bool) ($channel['feed_enabled'] ?? false);
    }

    /**
     * Returns one human-readable taxonomy feed label.
     *
     * @param array<string, mixed> $taxonomy Taxonomy row.
     * @param string $fallbackSlug Fallback slug when no taxonomy name is present.
     * @return string Human-readable taxonomy feed label.
     */
    private function taxonomyFeedLabel(array $taxonomy, string $fallbackSlug): string
    {
        $name = trim((string) ($taxonomy['name'] ?? ''));
        return $name !== '' ? $name : $fallbackSlug;
    }

    /**
     * Builds one relative feed route path from the base segment and extra parts.
     *
     * @param string $routeSegment Base feed route segment.
     * @param array<int, string> $extraSegments Extra route suffix segments.
     * @return string Relative feed route path.
     */
    private function buildFeedRoutePath(string $routeSegment, array $extraSegments = []): string
    {
        $segments = [trim($routeSegment, '/')];
        foreach ($extraSegments as $extraSegment) {
            $trimmed = trim($extraSegment, '/');
            if ($trimmed === '') {
                continue;
            }

            $segments[] = $trimmed;
        }

        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            $segments
        ));
    }

    /**
     * Builds public URL paths for category/tag listing rows and feed items.
     *
     * @param array<int, array<string, mixed>> $pages Public page rows.
     * @return array<int, array<string, mixed>> Public page rows with `url` fields.
     */
    private function decoratePageListPublicPaths(array $pages): array
    {
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $slug = $this->context->input()->slug((string) ($page['slug'] ?? ''));
            $pageId = (int) ($page['id'] ?? 0);
            if ($slug === null || $slug === '') {
                $pages[$index]['url'] = '/';
                continue;
            }

            $channelSlug = $this->context->input()->slug((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === null || $channelSlug === '') {
                $rootSegment = $this->publicChannelPageRouteService->canonicalSegment(
                    $slug,
                    $pageId,
                    (string) ($page['created'] ?? ''),
                    ChannelRouteParser::globalPageRouteMode($this->context->config()),
                    'inherit',
                    (string) $this->context->config()->get('content.separator', '-')
                );
                $pages[$index]['url'] = '/' . rawurlencode($rootSegment !== '' ? $rootSegment : $slug);
                continue;
            }

            $pages[$index]['url'] = '/'
                . rawurlencode($channelSlug)
                . '/'
                . rawurlencode(
                    $this->publicChannelPageRouteService->canonicalSegment(
                        $slug,
                        $pageId,
                        (string) ($page['created'] ?? ''),
                        ChannelRouteParser::effectiveChannelRouteMode($this->context->config(), (string) ($page['route_mode_effective'] ?? 'inherit')),
                        (string) ($page['route_separator_effective'] ?? 'inherit'),
                        (string) $this->context->config()->get('content.separator', '-')
                    )
                );
        }

        return $pages;
    }

    /**
     * Returns the current active public theme slug.
     *
     * @return string Active public theme slug.
     */
    private function currentPublicThemeSlug(): string
    {
        return $this->themeCatalogService->activeSlugFromConfig($this->context->config());
    }

    /**
     * Returns the public themes filesystem root.
     *
     * @return string Absolute public theme root.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService->root();
    }

    /**
     * Returns the shared public template resolver.
     *
     * @return PublicTemplateResolver Shared public template resolver.
     */
    private function publicTemplateResolver(): PublicTemplateResolver
    {
        if (!$this->publicTemplateResolver instanceof PublicTemplateResolver) {
            $this->publicTemplateResolver = new PublicTemplateResolver($this->context->input());
        }

        return $this->publicTemplateResolver;
    }

    /**
     * Returns the shared public template pipeline.
     *
     * @return PublicTemplatePipeline Shared public template pipeline.
     */
    private function publicTemplatePipeline(): PublicTemplatePipeline
    {
        if (!$this->publicTemplatePipeline instanceof PublicTemplatePipeline) {
            $this->publicTemplatePipeline = new PublicTemplatePipeline($this->publicTemplateResolver());
        }

        return $this->publicTemplatePipeline;
    }
}
