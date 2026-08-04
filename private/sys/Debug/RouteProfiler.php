<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/RouteProfiler.php
 * Builds normalized routing inventory rows for diagnostics and debugging views.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

use Raven\Core\Repository\ChannelShared;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Lib\Security\InputSanitizer;

/**
 * Builds normalized routing inventory rows for diagnostics views.
 *
 * Aggregates channels, pages, categories, tags, redirects, groups, and users
 * into a unified inventory and applies conflict detection via path-usage tracking.
 */
final class RouteProfiler
{
    private InputSanitizer $input;

    /**
     * @param InputSanitizer $input Shared text/path normalization helper.
     * @return void
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Builds all routing inventory rows from normalized route context inputs.
     *
     * @param array{
     *   reserved_prefixes?: array<int, string>,
     *   site_routing_trailing_slash?: bool,
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
     *   routing_groups?: array<int, array<string, mixed>>,
     *   routing_users?: array<int, array<string, mixed>>,
     *   channel_routing_options?: array<int, array<string, mixed>>,
     *   category_routing_options?: array<int, array<string, mixed>>,
     *   tag_routing_options?: array<int, array<string, mixed>>,
     *   redirect_routing_rows?: array<int, array<string, mixed>>,
     *   pages_for_routing?: array<int, array<string, mixed>>,
     *   build_page_url?: callable(string, int, string, string, string, string): string,
     *   channel_landing_map_builder?: callable(array): array,
     *   build_edit_url?: callable(string, array<string, mixed>): string,
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
        $buildEditUrl = $context['build_edit_url'] ?? null;
        $buildUserRouteSegment = $context['build_user_route_segment'] ?? null;
        $slugifyGroupName = $context['slugify_group_name'] ?? null;

        // Route inventory rendering depends on these builder callables being present and executable.
        if (
            !is_callable($buildPageUrl)
            || !is_callable($buildChannelLandingMap)
            || !is_callable($buildEditUrl)
            || !is_callable($buildUserRouteSegment)
            || !is_callable($slugifyGroupName)
        ) {
            throw new \RuntimeException('RouteProfiler requires callable routing resolver dependencies.');
        }

        $rows = [];
        $pathUsage = [];
        $reservedPrefixes = is_array($context['reserved_prefixes'] ?? null)
            ? array_values(array_filter(array_map('strval', $context['reserved_prefixes']), static fn (string $value): bool => $value !== ''))
            : [];
        $siteRoutingTrailingSlash = !empty($context['site_routing_trailing_slash']);
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
        $canonicalPublicUrl = static fn (string $path): string => PagePolicy::canonicalPath(
            $path,
            $siteRoutingTrailingSlash
        );

        $channelsById = [];
        // Index channel metadata by id so page rows can inherit effective channel routing options.
        foreach ($channelRoutingOptions as $channelOption) {
            $channelId = (int) ($channelOption['id'] ?? 0);
            // Ignore placeholder/invalid channel records from mixed option sources.
            if ($channelId > 0) {
                $channelsById[$channelId] = [
                    'slug' => (string) ($channelOption['slug'] ?? ''),
                    'name' => (string) ($channelOption['name'] ?? ''),
                    'parent_id' => ChannelShared::normalizeParentId($channelOption['parent_id'] ?? 0),
                    'route_mode' => (string) ($channelOption['route_mode'] ?? 'inherit'),
                    'route_separator' => (string) ($channelOption['route_separator'] ?? 'inherit'),
                ];
            }
        }
        // Backfill channel routing fields on each page row to simplify downstream URL building.
        foreach ($pagesForRouting as &$pageForRouting) {
            $channelId = (int) ($pageForRouting['channel'] ?? 0);
            $pageForRouting['channel_slug'] = (string) ($channelsById[$channelId]['slug'] ?? '');
            $pageForRouting['channel_path'] = $channelId > 0
                ? $this->channelPathForId($channelId, $channelsById)
                : '';
            $pageForRouting['channel_name'] = (string) ($channelsById[$channelId]['name'] ?? '');
            $pageForRouting['route_mode_effective'] = (string) ($channelsById[$channelId]['route_mode'] ?? 'inherit');
            $pageForRouting['route_separator_effective'] = (string) ($channelsById[$channelId]['route_separator'] ?? 'inherit');
        }
        unset($pageForRouting);
        $channelLandingMap = $buildChannelLandingMap($pagesForRouting);
        // Fallback to an empty map when custom builders return an unexpected type.
        if (!is_array($channelLandingMap)) {
            $channelLandingMap = [];
        }

        // Add global RSS route row only when feed feature and RSS route are both configured.
        if ($feedEnabled && $rssFeedRoute !== '') {
            $publicUrl = $canonicalPublicUrl('/' . $rssFeedRoute);
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'feed',
                'type_label' => 'Feed',
                'source_label' => 'RSS Feed',
                'edit_url' => (string) $buildEditUrl('feed', ['kind' => 'global']),
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => 'active',
                'status_label' => 'Active',
                'notes' => '',
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        // Add global Atom route row only when feed feature and Atom route are both configured.
        if ($feedEnabled && $atomFeedRoute !== '') {
            $publicUrl = $canonicalPublicUrl('/' . $atomFeedRoute);
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'feed',
                'type_label' => 'Feed',
                'source_label' => 'Atom Feed',
                'edit_url' => (string) $buildEditUrl('feed', ['kind' => 'global']),
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => 'active',
                'status_label' => 'Active',
                'notes' => '',
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        // Emit one inventory row per channel, including root-channel landing diagnostics.
        foreach ($channelRoutingOptions as $channel) {
            $channelId = (int) ($channel['id'] ?? 0);
            $channelSlug = trim((string) ($channel['slug'] ?? ''));
            // Skip invalid channel rows that cannot be represented as public routes.
            if ($channelId < 0 || $channelSlug === '') {
                continue;
            }

            $isRootChannel = ChannelShared::isRootChannelId($channelId)
                || ChannelShared::isRootChannelSlug($channelSlug);
            $channelPath = $isRootChannel ? '' : $this->channelPathForId($channelId, $channelsById);
            $landingKey = $channelPath !== '' ? $channelPath : $channelSlug;
            $landingSlug = $isRootChannel
                ? $this->rootLandingSlug($pagesForRouting)
                : trim((string) ($channelLandingMap[$landingKey] ?? $channelLandingMap[$channelSlug] ?? ''));
            $indexRouteMode = ChannelPolicy::normalizeChannelIndexRouteMode(
                (string) ($channel['index'] ?? 'auto')
            );
            $channelIndexTrailingSlash = $indexRouteMode === 'trailing_slash'
                || ($indexRouteMode === 'auto' && $siteRoutingTrailingSlash);
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
            // Reserved prefixes are intentionally blocked from public routing to protect system endpoints.
            if (!$isRootChannel && in_array($channelSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this channel route is not publicly reachable.';
            }

            $publicUrl = $isRootChannel
                ? '/'
                : ($indexRouteMode === 'redirect' && $hasLanding
                    ? PagePolicy::canonicalPath(
                        '/' . ($channelPath !== '' ? $channelPath : $channelSlug) . '/' . $landingSlug,
                        $siteRoutingTrailingSlash
                    )
                    : PagePolicy::canonicalPath(
                        '/' . ($channelPath !== '' ? $channelPath : $channelSlug),
                        $channelIndexTrailingSlash
                    ));
            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'channel',
                'type_label' => 'Channel',
                'source_label' => trim((string) ($channel['name'] ?? '')) !== '' ? (string) $channel['name'] : $channelSlug,
                'edit_url' => (string) $buildEditUrl('channel', ['id' => $channelId]),
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];

            // Channel-specific feeds only exist for non-root channels with feed support enabled.
            if (!$feedEnabled || $isRootChannel || !(bool) ($channel['feed_enabled'] ?? false)) {
                continue;
            }

            $channelLabel = trim((string) ($channel['name'] ?? '')) !== '' ? (string) $channel['name'] : $channelSlug;

            // Add channel-scoped RSS endpoint when RSS route prefix is configured.
            if ($rssFeedRoute !== '') {
                $feedUrl = PagePolicy::canonicalPath(
                    '/' . $rssFeedRoute . '/' . ($channelPath !== '' ? $channelPath : $channelSlug),
                    $siteRoutingTrailingSlash
                );
                $feedConflictKey = strtolower($feedUrl);
                $pathUsage[$feedConflictKey] = (int) ($pathUsage[$feedConflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'feed',
                    'type_label' => 'Feed',
                    'source_label' => 'RSS Feed (' . $channelLabel . ')',
                    'edit_url' => (string) $buildEditUrl('feed', ['kind' => 'channel', 'channel_id' => $channelId]),
                    'public_url' => $feedUrl,
                    'target_url' => $feedUrl,
                    'status_key' => 'active',
                    'status_label' => 'Active',
                    'notes' => '',
                    'is_conflict' => false,
                    '_conflict_key' => $feedConflictKey,
                ];
            }

            // Add channel-scoped Atom endpoint when Atom route prefix is configured.
            if ($atomFeedRoute !== '') {
                $feedUrl = PagePolicy::canonicalPath(
                    '/' . $atomFeedRoute . '/' . ($channelPath !== '' ? $channelPath : $channelSlug),
                    $siteRoutingTrailingSlash
                );
                $feedConflictKey = strtolower($feedUrl);
                $pathUsage[$feedConflictKey] = (int) ($pathUsage[$feedConflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'feed',
                    'type_label' => 'Feed',
                    'source_label' => 'Atom Feed (' . $channelLabel . ')',
                    'edit_url' => (string) $buildEditUrl('feed', ['kind' => 'channel', 'channel_id' => $channelId]),
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

        // Build page rows from effective route fields precomputed above.
        foreach ($pagesForRouting as $page) {
            $pageId = (int) ($page['id'] ?? 0);
            $pageSlug = trim((string) ($page['slug'] ?? ''));
            // Skip draft scaffolding rows that do not have a valid route identity yet.
            if ($pageId <= 0 || $pageSlug === '') {
                continue;
            }

            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            $channelPath = trim((string) ($page['channel_path'] ?? ''));
            $publicUrl = $buildPageUrl(
                $pageSlug,
                (int) ($page['id'] ?? 0),
                $channelPath !== '' ? $channelPath : $channelSlug,
                (string) ($page['created'] ?? ''),
                (string) ($page['route_mode_effective'] ?? 'inherit'),
                (string) ($page['route_separator_effective'] ?? 'inherit')
            );

            $statusKey = ($page['status'] ?? '') === 'published' ? 'published' : 'draft';
            $statusLabel = $statusKey === 'published' ? 'Published' : 'Draft';
            $notes = '';

            // Flag page routes that collide with reserved root/channel prefixes.
            if ($channelSlug === '' && in_array($pageSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level page route is not publicly reachable.';
            } elseif ($channelSlug !== '' && in_array(explode('/', $channelPath !== '' ? $channelPath : $channelSlug)[0], $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled page route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'page',
                'type_label' => 'Page',
                'source_label' => trim((string) ($page['title'] ?? '')) !== '' ? (string) $page['title'] : $pageSlug,
                'edit_url' => (string) $buildEditUrl('page', ['id' => $pageId]),
                'public_url' => $publicUrl,
                'target_url' => $publicUrl,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
                'notes' => $notes,
                'is_conflict' => false,
                '_conflict_key' => $conflictKey,
            ];
        }

        // Category route inventory is optional and only listed when category URLs are enabled.
        if ($categoryRoutesEnabled) {
            // Emit one route row per category with a valid id/slug pair.
            foreach ($categoryRoutingOptions as $category) {
                $categoryId = (int) ($category['id'] ?? 0);
                $categorySlug = trim((string) ($category['slug'] ?? ''));
                // Ignore malformed category rows that cannot map to a public URL.
                if ($categoryId <= 0 || $categorySlug === '') {
                    continue;
                }

                $publicUrl = $canonicalPublicUrl('/' . $categoryPrefix . '/' . $categorySlug);
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'category',
                    'type_label' => 'Category',
                    'source_label' => trim((string) ($category['name'] ?? '')) !== ''
                        ? (string) $category['name']
                        : $categorySlug,
                    'edit_url' => (string) $buildEditUrl('category', ['id' => $categoryId]),
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

        // Tag route inventory is optional and only listed when tag URLs are enabled.
        if ($tagRoutesEnabled) {
            // Emit one route row per tag with a valid id/slug pair.
            foreach ($tagRoutingOptions as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                $tagSlug = trim((string) ($tag['slug'] ?? ''));
                // Ignore malformed tag rows that cannot map to a public URL.
                if ($tagId <= 0 || $tagSlug === '') {
                    continue;
                }

                $publicUrl = $canonicalPublicUrl('/' . $tagPrefix . '/' . $tagSlug);
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $rows[] = [
                    'type_key' => 'tag',
                    'type_label' => 'Tag',
                    'source_label' => trim((string) ($tag['name'] ?? '')) !== '' ? (string) $tag['name'] : $tagSlug,
                    'edit_url' => (string) $buildEditUrl('tag', ['id' => $tagId]),
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

        // Group route inventory is optional and only listed when group profile URLs are enabled.
        if ($groupRoutingEnabled) {
            // Emit route rows only for user-visible groups with route exposure enabled.
            foreach ($routingGroups as $group) {
                $groupId = (int) ($group['id'] ?? 0);
                $groupName = trim((string) ($group['name'] ?? ''));
                // Skip incomplete group rows that cannot produce a stable route.
                if ($groupId <= 0 || $groupName === '') {
                    continue;
                }
                $groupRoleSlug = strtolower(trim((string) ($group['slug'] ?? '')));
                // Internal system groups do not expose public group profile routes.
                if (in_array($groupRoleSlug, ['guest', 'validating', 'banned'], true)) {
                    continue;
                }

                $routeEnabled = (int) ($group['route'] ?? 0) === 1;
                // Keep inventory aligned with explicit per-group route toggle.
                if (!$routeEnabled) {
                    continue;
                }

                $groupSlug = $this->input->slug((string) ($group['slug'] ?? ''));
                // Fall back to deterministic slugification when stored slug is missing/invalid.
                if ($groupSlug === null || $groupSlug === '') {
                    $groupSlug = (string) $slugifyGroupName($groupName);
                }
                // Skip groups whose names still cannot be converted into route-safe slugs.
                if ($groupSlug === '') {
                    continue;
                }

                $publicUrl = $canonicalPublicUrl('/' . $groupPrefix . '/' . $groupSlug);
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $memberCount = max(0, (int) ($group['member_count'] ?? 0));
                $statusLabel = $memberCount . ' Users';

                $rows[] = [
                    'type_key' => 'group',
                    'type_label' => 'Group',
                    'source_label' => $groupName,
                    'edit_url' => (string) $buildEditUrl('group', ['id' => $groupId]),
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

        // User profile route inventory is optional and only listed when profile URLs are enabled.
        if ($userRoutingEnabled) {
            // Emit one route row per routable user account.
            foreach ($routingUsers as $user) {
                $userId = (int) ($user['id'] ?? 0);
                $routeSegment = $buildUserRouteSegment($user);
                // Require both a valid user id and a non-empty route segment from the resolver.
                if ($userId <= 0 || !is_string($routeSegment) || $routeSegment === '') {
                    continue;
                }

                $publicUrl = $canonicalPublicUrl('/' . $profilePrefix . '/' . rawurlencode($routeSegment));
                $conflictKey = strtolower($publicUrl);
                $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

                $sourceLabel = trim((string) ($user['username'] ?? ''));
                // Keep source labels readable even when usernames are missing.
                if ($sourceLabel === '') {
                    $sourceLabel = 'User #' . $userId;
                }

                $groupStatusLabel = trim((string) ($user['groups_text'] ?? ''));
                // Use explicit fallback label for users without group membership text.
                if ($groupStatusLabel === '') {
                    $groupStatusLabel = 'No Groups';
                }

                $statusKey = 'groups_' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $groupStatusLabel) ?? 'none');
                $statusKey = trim($statusKey, '-');
                // Ensure status keys always have a deterministic non-empty fallback value.
                if ($statusKey === '') {
                    $statusKey = 'groups_none';
                }

                $rows[] = [
                    'type_key' => 'user',
                    'type_label' => 'User',
                    'source_label' => $sourceLabel,
                    'edit_url' => (string) $buildEditUrl('user', ['id' => $userId]),
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

        // Redirect rows are always included because they can conflict with any route namespace.
        foreach ($redirectRoutingRows as $redirect) {
            $redirectId = (int) ($redirect['id'] ?? 0);
            $redirectSlug = trim((string) ($redirect['slug'] ?? ''));
            // Skip incomplete redirect records that cannot resolve to a concrete path.
            if ($redirectId <= 0 || $redirectSlug === '') {
                continue;
            }

            $channelId = (int) ($redirect['channel'] ?? 0);
            $channelSlug = trim((string) ($redirect['channel_slug'] ?? ''));
            $channelPath = $channelId > 0 ? $this->channelPathForId($channelId, $channelsById) : '';
            $channelPath = $channelPath !== '' ? $channelPath : $channelSlug;
            $publicUrl = $channelPath === ''
                ? '/' . $redirectSlug
                : '/' . $channelPath . '/' . $redirectSlug;
            $publicUrl = $canonicalPublicUrl($publicUrl);
            $targetUrl = PagePolicy::canonicalRedirectTarget(
                trim((string) ($redirect['target'] ?? '')),
                $siteRoutingTrailingSlash
            );

            $statusKey = (int) ($redirect['active'] ?? 0) === 1 ? 'active' : 'inactive';
            $statusLabel = $statusKey === 'active' ? 'Active' : 'Inactive';
            $notes = '';

            // Flag redirect paths shadowed by reserved root/channel prefixes.
            if ($channelSlug === '' && in_array($redirectSlug, $reservedPrefixes, true)) {
                $notes = 'Reserved prefix; this root-level redirect route is not publicly reachable.';
            } elseif ($channelPath !== '' && in_array(explode('/', $channelPath)[0], $reservedPrefixes, true)) {
                $notes = 'Reserved channel prefix; this channeled redirect route is not publicly reachable.';
            }

            $conflictKey = strtolower($publicUrl);
            $pathUsage[$conflictKey] = (int) ($pathUsage[$conflictKey] ?? 0) + 1;

            $rows[] = [
                'type_key' => 'redirect',
                'type_label' => 'Redirect',
                'source_label' => trim((string) ($redirect['title'] ?? '')) !== '' ? (string) $redirect['title'] : $redirectSlug,
                'edit_url' => (string) $buildEditUrl('redirect', ['id' => $redirectId]),
                'public_url' => $publicUrl,
                'target_url' => $targetUrl,
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
            // Rows without a conflict key are temporary/malformed and cannot participate in conflict checks.
            if ($conflictKey === '') {
                continue;
            }

            $usageCount = (int) ($pathUsage[$conflictKey] ?? 0);
            // Clear temporary key when path is unique and no conflict metadata is needed.
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
            // Primary sort groups inventory rows by public path for visual diffability.
            if ($pathCompare !== 0) {
                return $pathCompare;
            }

            $typeCompare = strcasecmp((string) ($a['type_label'] ?? ''), (string) ($b['type_label'] ?? ''));
            // Secondary sort keeps like route types grouped within identical paths.
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcasecmp((string) ($a['source_label'] ?? ''), (string) ($b['source_label'] ?? ''));
        });

        return $rows;
    }

    /**
     * Builds one canonical channel path from indexed channel records.
     *
     * @param int $channelId Channel id whose ancestor path should be returned.
     * @param array<int, array<string, mixed>> $channelsById Channel rows indexed by id.
     * @return string Slash-separated channel path, or an empty string for invalid hierarchy data.
     */
    private function channelPathForId(int $channelId, array $channelsById): string
    {
        if ($channelId < 1 || !isset($channelsById[$channelId])) {
            return '';
        }

        $segments = [];
        $visited = [];
        $currentId = $channelId;
        // Walk ancestors with a cycle guard so diagnostics remain safe on malformed records.
        while ($currentId > ChannelShared::ROOT_CHANNEL_ID) {
            if (isset($visited[$currentId]) || !isset($channelsById[$currentId])) {
                return '';
            }

            $visited[$currentId] = true;
            $channel = $channelsById[$currentId];
            $slug = strtolower(trim((string) ($channel['slug'] ?? '')));
            if (!ChannelShared::isValidSlug($slug)) {
                return '';
            }

            array_unshift($segments, $slug);
            $parentId = ChannelShared::normalizeParentId($channel['parent_id'] ?? 0);
            if ($parentId === $currentId) {
                return '';
            }

            $currentId = $parentId;
        }

        return implode('/', $segments);
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

        // Scan root-channel pages and keep the best landing candidate by priority/date.
        foreach ($pagesForRouting as $page) {
            $channelId = (int) ($page['channel'] ?? 0);
            $channelSlug = trim((string) ($page['channel_slug'] ?? ''));
            // Skip non-root channels; only root pages are candidates for root landing resolution.
            if ($channelId !== ChannelShared::ROOT_CHANNEL_ID && $channelSlug !== '') {
                continue;
            }

            // Only published pages can serve as runtime landing routes.
            if (($page['status'] ?? '') !== 'published') {
                continue;
            }

            $pageSlug = trim((string) ($page['slug'] ?? ''));
            $priority = match ($pageSlug) {
                'home' => 0,
                'index' => 1,
                default => null,
            };
            // Only home/index slugs participate in root landing selection.
            if ($priority === null) {
                continue;
            }

            $publishedAt = trim((string) ($page['created'] ?? ''));
            $publishedTs = $publishedAt !== '' ? (int) strtotime($publishedAt) : 0;
            // Normalize invalid timestamps to zero so comparisons remain deterministic.
            if ($publishedTs < 0) {
                $publishedTs = 0;
            }

            // Prefer higher-priority slug, then newest publish time within same priority bucket.
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
