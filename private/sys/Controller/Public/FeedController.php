<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/FeedController.php
 * Split public feed controller for feed routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\TagRead;
use Raven\Core\Router\CategoryPolicy;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\FeedPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Core\Router\TagPolicy;
use Raven\Lib\Parser\FeedParser;

/**
 * Handles split public feed routes.
 */
final class FeedController
{
    private SharedController $context;
    private ChannelRead $channelRead;
    private PageRead $pageRead;
    private CategoryRead $categoryRead;
    private TagRead $tagRead;
    private FeedPolicy $feedPolicy;
    private FeedParser $feedParser;

    /**
     * @param SharedController $context Shared public request context.
     * @param ChannelRead $channelRead Channel repository read side for feed/channel label lookups.
     * @param PageRead $pageRead Page repository read side for feed and taxonomy listing rows.
     * @param CategoryRead $categoryRead Category repository read side for category feed resolution.
     * @param TagRead $tagRead Tag repository read side for tag feed resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        ChannelRead $channelRead,
        PageRead $pageRead,
        CategoryRead $categoryRead,
        TagRead $tagRead
    ) {
        $this->context = $context;
        $this->channelRead = $channelRead;
        $this->pageRead = $pageRead;
        $this->categoryRead = $categoryRead;
        $this->tagRead = $tagRead;
        $this->feedPolicy = new FeedPolicy($context->config(), $context->input());
        $this->feedParser = new FeedParser($context->config(), $context->input());
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
        // Feed routes are disabled when feed feature flag is off.
        if (!$this->feedPolicy->feedEnabled()) {
            $this->context->notFound();
            return;
        }

        $routeSegment = $format === 'atom' ? $this->feedPolicy->atomRoute() : $this->feedPolicy->rssRoute();
        // Missing route segment means this feed format is effectively disabled.
        if ($routeSegment === '') {
            $this->context->notFound();
            return;
        }

        $site = $this->context->siteData();
        $feedChannelSlug = '';
        $configuredFeedChannels = $this->feedParser->feedChannels();
        $scopeLabel = '';
        $scopeType = 'global';
        $scopeSlug = '';
        $pages = [];

        // Channel-scoped feed path: validate channel slug and channel feed availability.
        if ($channelSlug !== null) {
            $normalizedChannelSlug = strtolower(trim($channelSlug));
            // Empty channel slug cannot resolve to a valid channel feed.
            if ($normalizedChannelSlug === '') {
                $this->context->notFound();
                return;
            }

            $channel = $this->channelRead->findBySlug($normalizedChannelSlug);
            // Channel must exist and be feed-enabled for channel-scoped feed.
            if (!is_array($channel) || !$this->channelFeedEnabled($channel)) {
                $this->context->notFound();
                return;
            }

            $feedChannelSlug = $normalizedChannelSlug;
            $scopeLabel = $this->channelLabel($feedChannelSlug);
            $scopeType = 'channel';
            $scopeSlug = $feedChannelSlug;
            $pages = $this->pageRead->listRecentPublished($this->feedParser->feedItems(), $feedChannelSlug);
        } else {
            // Global feed may include all channels or a configured subset.
            if (in_array('all', $configuredFeedChannels, true)) {
                $pages = $this->pageRead->listRecentPublished($this->feedParser->feedItems(), null);
            } else {
                $selectedFeedChannels = array_values(array_filter(
                    $configuredFeedChannels,
                    static fn (string $configuredChannel): bool => $configuredChannel !== ''
                ));
                // Single configured channel collapses scope label/type to channel mode.
                if (count($selectedFeedChannels) === 1) {
                    $feedChannelSlug = $selectedFeedChannels[0];
                    $scopeLabel = $this->channelLabel($feedChannelSlug);
                    $scopeType = 'channel';
                    $scopeSlug = $feedChannelSlug;
                } elseif ($selectedFeedChannels !== []) {
                    $scopeLabel = 'Selected Channels';
                    $scopeType = 'channels';
                }

                $pages = $this->pageRead->listRecentPublishedForChannels(
                    $this->feedParser->feedItems(),
                    $selectedFeedChannels
                );
            }

            // Any resolved channel slug means channel-scoped payload metadata.
            if ($feedChannelSlug !== '') {
                $scopeType = 'channel';
            }
        }

        $feedPayload = $this->buildFeedPayload(
            $format,
            $this->feedRoutePath(
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
        // Taxonomy feed routes are disabled when feed feature flag is off.
        if (!$this->feedPolicy->feedEnabled()) {
            $this->context->notFound();
            return;
        }

        $routeSegment = $format === 'atom' ? $this->feedPolicy->atomRoute() : $this->feedPolicy->rssRoute();
        // Missing route segment means this feed format is effectively disabled.
        if ($routeSegment === '') {
            $this->context->notFound();
            return;
        }

        $normalizedSlug = strtolower(trim($taxonomySlug));
        // Taxonomy feed requires a non-empty normalized taxonomy slug.
        if ($normalizedSlug === '') {
            $this->context->notFound();
            return;
        }

        $site = $this->context->siteData();
        $scopeLabel = '';
        $routeSuffix = [];
        $pages = [];

        // Category taxonomy feed branch.
        if ($taxonomyType === 'category') {
            // Category feed branch requires category routes to be enabled.
            if (!CategoryPolicy::categoryRouteEnabled($this->context->config())) {
                $this->context->notFound();
                return;
            }

            $categoryPrefix = CategoryPolicy::categoryRoutePrefix($this->context->config(), $this->context->input());
            $category = $this->categoryRead->findBySlug($normalizedSlug);
            // Category must exist for taxonomy feed rendering.
            if (!is_array($category)) {
                $this->context->notFound();
                return;
            }

            $pageResult = $this->pageRead->listPageByCategorySlug($normalizedSlug, $this->feedParser->feedItems(), 0);
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $scopeLabel = $this->taxonomyLabel($category, $normalizedSlug);
            $routeSuffix = [$categoryPrefix, $normalizedSlug];
        // Tag taxonomy feed branch.
        } elseif ($taxonomyType === 'tag') {
            // Tag feed branch requires tag routes to be enabled.
            if (!TagPolicy::tagRouteEnabled($this->context->config())) {
                $this->context->notFound();
                return;
            }

            $tagPrefix = TagPolicy::tagRoutePrefix($this->context->config(), $this->context->input());
            $tag = $this->tagRead->findBySlug($normalizedSlug);
            // Tag must exist for taxonomy feed rendering.
            if (!is_array($tag)) {
                $this->context->notFound();
                return;
            }

            $pageResult = $this->pageRead->listPageByTagSlug($normalizedSlug, $this->feedParser->feedItems(), 0);
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $scopeLabel = $this->taxonomyLabel($tag, $normalizedSlug);
            $routeSuffix = [$tagPrefix, $normalizedSlug];
        } else {
            $this->context->notFound();
            return;
        }

        $feedPayload = $this->buildFeedPayload(
            $format,
            $this->feedRoutePath($routeSegment, $routeSuffix),
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
        // Fall back to composed route URL when current URL is unavailable.
        if ($feedUrl === '') {
            $feedUrl = rtrim((string) ($site['url'] ?? ''), '/') . '/' . ltrim($routePath, '/');
        }

        $siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
        // Ensure non-empty site name for feed title/description output.
        if ($siteName === '') {
            $siteName = 'Raven CMS';
        }

        $formatLabel = strtoupper($format);
        $title = $siteName . ' ' . $formatLabel . ' Feed';
        $description = 'Latest pages from ' . $siteName . '.';
        // Scope label customizes title/description for channel/taxonomy feeds.
        if ($scopeLabel !== '') {
            $title = $siteName . ' ' . $formatLabel . ' Feed (' . $scopeLabel . ')';
            $description = 'Latest pages from ' . $scopeLabel . ' on ' . $siteName . '.';
        }

        $items = $this->buildFeedItems($pages, $site);
        $updatedTimestamp = time();
        // Use newest feed-item timestamp when items are available.
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
    private function buildFeedItems(array $pages, array $site): array
    {
        $pages = $this->buildPageUrls($pages);
        $siteUrl = rtrim((string) ($site['url'] ?? ''), '/');
        $result = [];

        // Normalize each source page row into feed-item payload fields.
        foreach ($pages as $page) {
            // Skip malformed source rows.
            if (!is_array($page)) {
                continue;
            }

            $path = trim((string) ($page['url'] ?? ''));
            // Missing path falls back to site root.
            if ($path === '') {
                $path = '/';
            }
            // Feed item path must be absolute path before concatenation.
            if (!str_starts_with($path, '/')) {
                $path = '/' . ltrim($path, '/');
            }

            $absoluteUrl = $siteUrl !== '' ? $siteUrl . $path : $path;
            $title = trim((string) ($page['title'] ?? ''));
            // Fallback feed item title to page slug when title is empty.
            if ($title === '') {
                $title = trim((string) ($page['slug'] ?? ''));
            }
            // Final fallback avoids empty titles in feed readers.
            if ($title === '') {
                $title = 'Untitled';
            }

            $description = trim((string) ($page['description'] ?? ''));
            $createdAt = trim((string) ($page['created'] ?? ''));
            $timestamp = strtotime($createdAt);
            // Invalid timestamps fall back to current time for feed formatting.
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
    private function channelLabel(string $channelSlug): string
    {
        $normalized = strtolower(trim($channelSlug));
        // Empty channel slug indicates all-channel feed scope.
        if ($normalized === '') {
            return 'All Channels';
        }

        // Root pseudo-channel gets dedicated label.
        if ($normalized === 'root') {
            return 'Root';
        }

        $channel = $this->channelRead->findBySlug($normalized);
        // Unknown channels fall back to normalized slug label.
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
    private function taxonomyLabel(array $taxonomy, string $fallbackSlug): string
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
    private function feedRoutePath(string $routeSegment, array $extraSegments = []): string
    {
        $segments = [trim($routeSegment, '/')];
        // Append non-empty suffix segments for scoped feed paths.
        foreach ($extraSegments as $extraSegment) {
            $trimmed = trim($extraSegment, '/');
            // Skip empty suffix segments.
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
    private function buildPageUrls(array $pages): array
    {
        // Build route URLs for each feed source page row.
        foreach ($pages as $index => $page) {
            // Ignore malformed source rows.
            if (!is_array($page)) {
                continue;
            }

            $slug = $this->context->input()->slug((string) ($page['slug'] ?? ''));
            $pageId = (int) ($page['id'] ?? 0);
            // Missing/invalid slug falls back to home path placeholder.
            if ($slug === null || $slug === '') {
                $pages[$index]['url'] = '/';
                continue;
            }

            $channelSlug = $this->context->input()->slug((string) ($page['channel_slug'] ?? ''));
            // Root-scope URL branch for pages without channel slug.
            if ($channelSlug === null || $channelSlug === '') {
                $rootSegment = PagePolicy::buildRouteSegment($this->context->input(), 
                    $slug,
                    $pageId,
                    (string) ($page['created'] ?? ''),
                    ChannelPolicy::globalPageRouteMode($this->context->config()),
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
                    PagePolicy::buildRouteSegment($this->context->input(), 
                        $slug,
                        $pageId,
                        (string) ($page['created'] ?? ''),
                        ChannelPolicy::effectiveChannelRouteMode($this->context->config(), (string) ($page['route_mode_effective'] ?? 'inherit')),
                        (string) ($page['route_separator_effective'] ?? 'inherit'),
                        (string) $this->context->config()->get('content.separator', '-')
                    )
                );
        }

        return $pages;
    }

}
