<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/RoutingInventoryBuilder.php
 * Builds normalized routing inventory rows for the panel routing diagnostics view.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Parser\ChannelRepoParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Builds normalized routing inventory rows for panel diagnostics views.
 *
 * Aggregates channels, pages, categories, tags, redirects, groups, and users
 * into a unified inventory and applies conflict detection via path-usage tracking.
 */
final class RoutingInventoryBuilder
{
    private InputSanitizer $input;

    /**
     * @param InputSanitizer $input Shared text/path normalization helper.
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Builds all routing inventory rows for the panel routing diagnostics view.
     *
     * @param array{
     *   reserved_prefixes?: array<int, string>,
     *   channel_index_template_exists?: bool,
     *   feed_enabled?: bool,
     *   rss_feed_route?: string,
     *   atom_feed_route?: string,
     *   category_prefix?: string,
     *   tag_prefix?: string,
     *   profile_prefix?: string,
     *   profile_routes_enabled?: bool,
     *   group_prefix?: string,
     *   group_routes_enabled?: bool,
     *   can_edit_configuration?: bool,
     *   can_edit_pages?: bool,
     *   can_edit_channels?: bool,
     *   can_edit_categories?: bool,
     *   can_edit_tags?: bool,
     *   can_edit_redirects?: bool,
     *   can_edit_users?: bool,
     *   can_edit_groups?: bool,
     *   routing_groups?: array<int, array<string, mixed>>,
     *   routing_users?: array<int, array<string, mixed>>,
     *   channel_routing_options?: array<int, array<string, mixed>>,
     *   category_routing_options?: array<int, array<string, mixed>>,
     *   tag_routing_options?: array<int, array<string, mixed>>,
     *   redirect_routing_rows?: array<int, array<string, mixed>>,
     *   pages_for_routing?: array<int, array<string, mixed>>,
     *   build_page_url?: callable(string, int, string, string, string, string): string,
     *   channel_landing_map_builder?: callable(array): array,
     *   panel_url?: callable(string): string,
     *   build_user_route_segment?: callable(array): ?string,
     *   slugify_group_name?: callable(string): string
     * } $context Full routing context including data rows and URL-building callables.
     * @return array<int, array{
     *   type_key: string,
     *   type_label: string,
     *   source_label: string,
     *   edit_url: string,
     *   public_url: string,
     *   target_url: string,
     *   status_key: string,
     *   status_label: string,
     *   notes: string,
     *   is_conflict: bool
     * }> Normalized inventory rows sorted by public URL.
     * @throws \RuntimeException When required URL-building callables are missing from context.
     */
    public function buildRows(array $context): array
    {
        $buildPageUrl = $context['build_page_url'] ?? null;
        $buildChannelLandingMap = $context['channel_landing_map_builder'] ?? null;
        $panelUrl = $context['panel_url'] ?? null;
        $buildUserRouteSegment = $context['build_user_route_segment'] ?? null;
        $slugifyGroupName = $context['slugify_group_name'] ?? null;

        if (
            !is_callable($buildPageUrl)
            || !is_callable($buildChannelLandingMap)
            || !is_callable($panelUrl)
            || !is_callable($buildUserRouteSegment)
            || !is_callable($slugifyGroupName)
        ) {
            throw new \RuntimeException('RoutingInventoryBuilder requires callable page/url/identity resolvers.');
        }

        $rows = [];
        $pathUsage = [];
        $reservedPrefixes = is_array($context['reserved_prefixes'] ?? null)
            ? array_values(array_filter(array_map('strval', $context['reserved_prefixes']), static fn (string $value): bool => $value !== ''))
            : [];
        $channelIndexTemplateExists = !empty($context['channel_index_template_exists']);
        $categoryPrefix = trim((string) ($context['category_prefix'] ?? ''));
        $tagPrefix = trim((string) ($context['tag_prefix'] ?? ''));
        $profilePrefix = trim((string) ($context['profile_prefix'] ?? ''));
        $profileRoutesEnabled = !empty($context['profile_routes_enabled']);
        $groupPrefix = trim((string) ($context['group_prefix'] ?? ''));
        $groupRoutesEnabled = !empty($context['group_routes_enabled']);
        $feedEnabled = !empty($context['feed_enabled']);
        $rssFeedRoute = trim((string) ($context['rss_feed_route'] ?? ''));
        $atomFeedRoute = trim((string) ($context['atom_feed_route'] ?? ''));
        $canEditConfiguration = !empty($context['can_edit_configuration']);
        $canEditPages = !empty($context['can_edit_pages']);
        $canEditChannels = !empty($context['can_edit_channels']);
        $canEditCategories = !empty($context['can_edit_categories']);
        $canEditTags = !empty($context['can_edit_tags']);
        $canEditRedirects = !empty($context['can_edit_redirects']);
        $canEditUsers = !empty($context['can_edit_users']);
        $canEditGroups = !empty($context['can_edit_groups']);
        $groupRoutingEnabled = $groupRoutesEnabled && $groupPrefix !== '';
        $userRoutingEnabled = $profileRoutesEnabled && $profilePrefix !== '';
        $routingGroups = is_array($context['routing_groups'] ?? null) ? $context['routing_groups'] : [];
        $routingUsers = is_array($context['routing_users'] ?? null) ? $context['routing_users'] : [];
        $categoryRoutesEnabled = $categoryPrefix !== '';
        $tagRoutesEnabled = $tagPrefix !== '';
        $channelRoutingOptions = is_array($context['channel_routing_options'] ?? null)
            ? $context['channel_routing_options']
            : [];
        $categoryRoutingOptions = is_array($context['category_routing_options'] ?? null)
            ? $context['category_routing_options']
            : [];
        $tagRoutingOptions = is_array($context['tag_routing_options'] ?? null)
            ? $context['tag_routing_options']
            : [];
        $redirectRoutingRows = is_array($context['redirect_routing_rows'] ?? null)
            ? $context['redirect_routing_rows']
            : [];
        $pagesForRouting = is_array($context['pages_for_routing'] ?? null) ? $context['pages_for_routing'] : [];

        $channelsById = [];
        foreach ($channelRoutingOptions as $channelOption) {
            $channelId = (int) ($channelOption['id'] ?? 0);
            if ($channelId > 0) {
                $channelsById[$channelId] = [
                    'slug' => (string) ($channelOption['slug'] ?? ''),
                    'name' => (string) ($channelOption['name'] ?? ''),
                    'route_mode' => (string) ($channelOption['route_mode'] ?? 'inherit'),
                    'route_separator' => (string) ($channelOption['route_separator'] ?? 'inherit'),
                ];
            }
        }
        foreach ($pagesForRouting as &$pageForRouting) {
            $channelId = (int) ($pageForRouting['channel'] ?? 0);
            $pageForRouting['channel_slug'] = (string) ($channelsById[$channelId]['slug'] ?? '');
            $pageForRouting['channel_name'] = (string) ($channelsById[$channelId]['name'] ?? '');
            $pageForRouting['route_mode_effective'] = (string) ($channelsById[$channelId]['route_mode'] ?? 'inherit');
            $pageForRouting['route_separator_effective'] = (string) ($channelsById[$channelId]['route_separator'] ?? 'inherit');
        }
        unset($pageForRouting);
        $channelLandingMap = $buildChannelLandingMap($pagesForRouting);
        if (!is_array($channelLandingMap)) {
            $channelLandingMap = [];
        }

        if ($feedEnabled && $rssFeedRoute !== '') {
            $publicUrl = '/' . $rssFeedRoute;
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'feed',
                'type_label' => 'Feed',
                'source_label' => 'RSS Feed',
                'edit_url' => $canEditConfiguration ? (string) $panelUrl('/configuration?tab=content') : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => 'active',
                'status_label' => 'Active',
                'notes' => '',
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        if ($feedEnabled && $atomFeedRoute !== '') {
            $publicUrl = '/' . $atomFeedRoute;
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'feed',
                'type_label' => 'Feed',
                'source_label' => 'Atom Feed',
                'edit_url' => $canEditConfiguration ? (string) $panelUrl('/configuration?tab=content') : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => 'active',
                'status_label' => 'Active',
                'notes' => '',
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        foreach ($channelRoutingOptions as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $channelSlug = trim((string) ($channel['slug'] ?? ''));
            if ($channelId < 0 || $channelSlug === '') {
                continue;
            }

            $isRootChannel = ChannelRepoParser::isRootChannelId($channelId)
                || ChannelRepoParser::isRootChannelSlug($channelSlug);
            $landingSlug = $isRootChannel
                ? $this->rootLandingSlug($pagesForRouting)
                : trim((string) ($channelLandingMap[$channelSlug] ?? ''));
            $hasLanding = $landingSlug !== '';
            $statusKey = $hasLanding ? 'active' : 'missing';
            $statusLabel = $hasLanding
                ? 'Active'
                : ($channelIndexTemplateExists ? 'Missing Index' : 'Missing Template');
            $notes = $hasLanding
                ? ($isRootChannel
                    ? ('Root landing resolves using slug "' . $landingSlug . '".')
                    : ('Channel landing resolves using slug "' . $landingSlug . '".'))
                : 'No published channel landing page found (requires slug home or index).';
            if (!$isRootChannel && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this channel route is not publicly reachable.';
            }

            $publicUrl = $isRootChannel ? '/' : ('/' . $channelSlug);
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'channel',
                'type_label' => 'Channel',
                'source_label' => trim((string) ($channel['name'] ?? '')) !== '' ? (string) $channel['name'] : $channelSlug,
                'edit_url' => $canEditChannels ? (string) $panelUrl('/channel/edit/' . $channelId) : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];

            if (!$feedEnabled || $isRootChannel || !(bool) ($channel['feed_enabled'] ?? false)) {
                continue;
            }

            $channelLabel = trim((string) ($channel['name'] ?? '')) !== '' ? (string) $channel['name'] : $channelSlug;

            if ($rssFeedRoute !== '') {
                $feedUrl = '/' . $rssFeedRoute . '/' . $channelSlug;
                $feedConflictKey = strtolower($feedUrl);
                $pathUsage[$feedConflictKey] = (int) ($pathUsage[$feedConflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'feed',
                    'type_label' => 'Feed',
                    'source_label' => 'RSS Feed (' . $channelLabel . ')',
                    'edit_url' => $canEditChannels ? (string) $panelUrl('/channel/edit/' . $channelId) : '',
                    'public_url' => $feedUrl,
                    'target_url' => $feedUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $feedConflictKey,
                ];
            }

            if ($atomFeedRoute !== '') {
                $feedUrl = '/' . $atomFeedRoute . '/' . $channelSlug;
                $feedConflictKey = strtolower($feedUrl);
                $pathUsage[$feedConflictKey] = (int) ($pathUsage[$feedConflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'feed',
                    'type_label' => 'Feed',
                    'source_label' => 'Atom Feed (' . $channelLabel . ')',
                    'edit_url' => $canEditChannels ? (string) $panelUrl('/channel/edit/' . $channelId) : '',
                    'public_url' => $feedUrl,
                    'target_url' => $feedUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $feedConflictKey,
                ];
            }
        }

        foreach ($pagesForRouting as $page) {
            $pageId = (int) ($page['id'] ?? 0);
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            if ($pageId <= 0 || $pageSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            $publicUrl = $buildPageUrl(
                $pageSlug,
                (int) ($page['id'] ?? 0),
                $channelSlug,
                (string) ($page['created'] ?? ''),
                (string) ($page['route_mode_effective'] ?? 'inherit'),
                (string) ($page['route_separator_effective'] ?? 'inherit')
            );

            $statusKey = ($page['status'] ?? '') === 'published' ? 'published' : 'draft';
            $statusLabel = $statusKey === 'published' ? 'Published' : 'Draft';
            $notes = '';

            if ($channelSlug === '' && in_array($pageSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level page route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled page route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'page',
                'type_label' => 'Page',
                'source_label' => trim((string) ($page['title'] ?? '')) !== '' ? (string) $page['title'] : $pageSlug,
                'edit_url' => $canEditPages ? (string) $panelUrl('/page/edit/' . $pageId) : '',
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        if ($categoryRoutesEnabled) {
            foreach ($categoryRoutingOptions as $category) {
                $categoryId = (int) ($category['id'] ?? 0);
                $categorySlug = trim((string) ($category['slug'] ?? ''));
                if ($categoryId <= 0 || $categorySlug === '') {
                    continue;
                }

                $publicUrl = '/' . $categoryPrefix . '/' . $categorySlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'category',
                    'type_label' => 'Category',
                    'source_label' => trim((string) ($category['name'] ?? '')) !== ''
                        ? (string) $category['name']
                        : $categorySlug,
                    'edit_url' => $canEditCategories ? (string) $panelUrl('/category/edit/' . $categoryId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($tagRoutesEnabled) {
            foreach ($tagRoutingOptions as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                $tagSlug = trim((string) ($tag['slug'] ?? ''));
                if ($tagId <= 0 || $tagSlug === '') {
                    continue;
                }

                $publicUrl = '/' . $tagPrefix . '/' . $tagSlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'tag',
                    'type_label' => 'Tag',
                    'source_label' => trim((string) ($tag['name'] ?? '')) !== '' ? (string) $tag['name'] : $tagSlug,
                    'edit_url' => $canEditTags ? (string) $panelUrl('/tag/edit/' . $tagId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($groupRoutingEnabled) {
            foreach ($routingGroups as $group) {
                $groupId = (int) ($group['id'] ?? 0);
                $groupName = trim((string) ($group['name'] ?? ''));
                if ($groupId <= 0 || $groupName === '') {
                    continue;
                }
                $groupRoleSlug = strtolower(trim((string) ($group['slug'] ?? '')));
                if (in_array($groupRoleSlug, ['guest', 'validating', 'banned'], true)) {
                    continue;
                }

                $routeEnabled = (int) ($group['route'] ?? 0) === 1;
                if (!$routeEnabled) {
                    continue;
                }

                $groupSlug = $this->input->slug((string) ($group['slug'] ?? ''));
                if ($groupSlug === null || $groupSlug === '') {
                    $groupSlug = (string) $slugifyGroupName($groupName);
                }
                if ($groupSlug === '') {
                    continue;
                }

                $publicUrl = '/' . $groupPrefix . '/' . $groupSlug;
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $memberCount = max(0, (int) ($group['member_count'] ?? 0));
                $statusLabel = $memberCount . ' Users';

                $rows[] = [
                    'type_key' => 'group',
                    'type_label' => 'Group',
                    'source_label' => $groupName,
                    'edit_url' => $canEditGroups ? (string) $panelUrl('/group/edit/' . $groupId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => 'users_' . $memberCount,
                    'status_label' => $statusLabel,
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        if ($userRoutingEnabled) {
            foreach ($routingUsers as $user) {
                $userId = (int) ($user['id'] ?? 0);
                $routeSegment = $buildUserRouteSegment($user);
                if ($userId <= 0 || !is_string($routeSegment) || $routeSegment === '') {
                    continue;
                }

                $publicUrl = '/' . $profilePrefix . '/' . rawurlencode($routeSegment);
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $sourceLabel = trim((string) ($user['username'] ?? ''));
                if ($sourceLabel === '') {
                    $sourceLabel = 'User #' . $userId;
                }

                $groupStatusLabel = trim((string) ($user['groups_text'] ?? ''));
                if ($groupStatusLabel === '') {
                    $groupStatusLabel = 'No Groups';
                }

                $statusKey = 'groups_' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $groupStatusLabel) ?? 'none');
                $statusKey = trim($statusKey, '-');
                if ($statusKey === '') {
                    $statusKey = 'groups_none';
                }

                $rows[] = [
                    'type_key' => 'user',
                    'type_label' => 'User',
                    'source_label' => $sourceLabel,
                    'edit_url' => $canEditUsers ? (string) $panelUrl('/user/edit/' . $userId) : '',
                    'public_url' => $publicUrl,
                    'target_url' => $publicUrl,
                    'status_key' => $statusKey,
                    'status_label' => $groupStatusLabel,
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $conflictKey,
                ];
            }
        }

        foreach ($redirectRoutingRows as $redirect) {
            $redirectId = (int) ($redirect['id'] ?? 0);
            $redirectSlug = trim((string) ($redirect['slug'] ?? ''));
            if ($redirectId <= 0 || $redirectSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($redirect['channel_slug'] ?? ''));
            $publicUrl = $channelSlug === ''
                ? '/' . $redirectSlug
                : '/' . $channelSlug . '/' . $redirectSlug;

            $statusKey = (int) ($redirect['active'] ?? 0) === 1 ? 'active' : 'inactive';
            $statusLabel = $statusKey === 'active' ? 'Active' : 'Inactive';
            $notes = '';

            if ($channelSlug === '' && in_array($redirectSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level redirect route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled redirect route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'redirect',
                'type_label' => 'Redirect',
                'source_label' => trim((string) ($redirect['title'] ?? '')) !== '' ? (string) $redirect['title'] : $redirectSlug,
                'edit_url' => $canEditRedirects ? (string) $panelUrl('/redirect/edit/' . $redirectId) : '',
                'public_url' => $publicUrl,
                'target_url' => trim((string) ($redirect['target'] ?? '')),
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        // Second pass: mark any path that appears more than once as a conflict.
        foreach ($rows as $index => $row) {
            $conflictKey = (string) ($row['_conflict_key'] ?? '');
            if ($conflictKey === '') {
                continue;
            }

            $usageCount = (int) ($pathUsage[$conflictKey] ?? 0);
            if ($usageCount <= 1) {
                unset($rows[$index]['_conflict_key']);
                continue;
            }

            $rows[$index]['is_conflict'] = true;
            $suffix = 'Path conflict with ' . (string) ($usageCount - 1) . ' other route(s).';
            $existingNotes = trim((string) ($rows[$index]['notes'] ?? ''));
            $rows[$index]['notes'] = $existingNotes === '' ? $suffix : ($existingNotes . ' ' . $suffix);
            unset($rows[$index]['_conflict_key']);
        }

        usort($rows, static function (array $a, array $b): int {
            $pathCompare = strcasecmp((string) ($a['public_url'] ?? ''), (string) ($b['public_url'] ?? ''));
            if ($pathCompare !== 0) {
                return $pathCompare;
            }

            $typeCompare = strcasecmp((string) ($a['type_label'] ?? ''), (string) ($b['type_label'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcasecmp((string) ($a['source_label'] ?? ''), (string) ($b['source_label'] ?? ''));
        });

        return $rows;
    }

    /**
     * Returns the best-matching root landing page slug from published root-channel pages.
     *
     * Prefers `home` over `index` when both exist, then breaks ties by newest published date.
     *
     * @param array<int, array<string, mixed>> $pagesForRouting All routing page rows.
     * @return string Best slug (`home` or `index`) or empty string when none are published.
     */
    private function rootLandingSlug(array $pagesForRouting): string
    {
        $bestSlug = '';
        $bestPriority = PHP_INT_MAX;
        $bestPublishedTs = -1;

        foreach ($pagesForRouting as $page) {
            $channelId = (int) ($page['channel'] ?? 0);
            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            if ($channelId !== ChannelRepoParser::ROOT_CHANNEL_ID && $channelSlug !== '') {
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

            $publishedAt = trim((string) ($page['created'] ?? ''));
            $publishedTs = $publishedAt !== '' ? (int) strtotime($publishedAt) : 0;
            if ($publishedTs < 0) {
                $publishedTs = 0;
            }

            if (
                $bestSlug === ''
                || $priority < $bestPriority
                || ($priority === $bestPriority && $publishedTs > $bestPublishedTs)
            ) {
                $bestSlug = $pageSlug;
                $bestPriority = $priority;
                $bestPublishedTs = $publishedTs;
            }
        }

        return $bestSlug;
    }
}
