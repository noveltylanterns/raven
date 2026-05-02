<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/RoutingController.php
 * Split panel routing controller for routing inventory routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Config;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Debug\RouteProfiler;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\Panel\PanelAccess;
use Raven\Lib\Format\Csv;
use Raven\Lib\Parser\CategoryRouteParser;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\FeedRouteParser;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Parser\TagRouteParser;
use Raven\Lib\Parser\TaxonomyRepoParser;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Panel\PanelRoutingPreviewService;
use Raven\Lib\View\Public\ThemeCatalog;

/**
 * Handles the split panel routing diagnostics routes.
 *
 * Owns the `/routing*` route family so routing inventory reads, preview-path
 * synthesis, and CSV export no longer ride through the broader
 * system-management controller.
 */
final class RoutingController
{
    private SharedController $context;
    private Config $config;
    private InputSanitizer $input;
    private string $root;
    private ChannelRead $channelRepo;
    private PageRead $pageRepo;
    private RedirectRead $redirectRepo;
    private UserRead $userRepo;
    /** @var Closure(): TaxonomyRepoParser */
    private Closure $taxonomyLookupRepoResolver;
    private ?TaxonomyRepoParser $taxonomyLookupRepo = null;
    private LoginIdentifierResolver $identifierResolver;
    private ?RouteProfiler $routeProfiler = null;
    private ?Csv $csvHandler = null;
    private ?FeedRouteParser $feedParser = null;
    private ?GroupRouteParser $groupParser = null;
    private ThemeCatalog $themeCatalogService;
    private ?PanelRoutingPreviewService $panelRoutingPreviewService = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param Config $config Runtime configuration reader for public route state.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param string $root Project root path for routing preview/theme lookups.
     * @param ChannelRead $channelRepo Channel repository read side for routing option rows.
     * @param PageRead $pageRepo Page repository read side for routing inventory rows.
     * @param RedirectRead $redirectRepo Redirect repository read side for routing inventory rows.
     * @param UserRead $userRepo User repository read side for routing inventory rows.
     * @param callable(): TaxonomyRepoParser $taxonomyLookupRepoResolver Lazy taxonomy lookup parser resolver.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for route preview rendering.
     * @return void
     */
    public function __construct(
        SharedController $context,
        Config $config,
        InputSanitizer $input,
        string $root,
        ChannelRead $channelRepo,
        PageRead $pageRepo,
        RedirectRead $redirectRepo,
        UserRead $userRepo,
        callable $taxonomyLookupRepoResolver,
        ThemeCatalog $themeCatalogService
    ) {
        $this->context = $context;
        $this->config = $config;
        $this->input = $input;
        $this->root = rtrim($root, '/\\');
        $this->channelRepo = $channelRepo;
        $this->pageRepo = $pageRepo;
        $this->redirectRepo = $redirectRepo;
        $this->userRepo = $userRepo;
        $this->taxonomyLookupRepoResolver = Closure::fromCallable($taxonomyLookupRepoResolver);
        $this->identifierResolver = new LoginIdentifierResolver();
        $this->themeCatalogService = $themeCatalogService;
    }

    /**
     * Renders the routing inventory page.
     *
     * @return void
     */
    public function routing(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $routeRows = $this->routingRowsForPanel();
        $summary = [
            'total' => count($routeRows),
            'page' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'page')),
            'channel' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'channel')),
            'redirect' => count(array_filter($routeRows, static fn (array $row): bool => (string) ($row['type_key'] ?? '') === 'redirect')),
            'conflicts' => count(array_filter($routeRows, static fn (array $row): bool => !empty($row['is_conflict']))),
        ];
        $initialSearch = $this->input->text(is_string($_GET['search'] ?? null) ? $_GET['search'] : null, 200);

        $this->context->renderPanel('panel/routing', [
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'routing',
            'pageTitle' => 'Routing Table',
            'routeRows' => $routeRows,
            'routeSummary' => $summary,
            'initialSearch' => $initialSearch,
        ]);
    }

    /**
     * Exports routing inventory rows as CSV.
     *
     * @return void
     */
    public function routingExport(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('routing', 'view')) {
            return;
        }

        $rows = $this->routingRowsForPanel();
        $filename = 'routing-inventory-' . gmdate('Ymd-His') . '.csv';
        $this->csvHandler()->streamToOutput(
            $filename,
            (static function (array $rows): \Generator {
                foreach ($rows as $row) {
                    yield [
                        (string) ($row['type_label'] ?? ''),
                        (string) ($row['source_label'] ?? ''),
                        (string) ($row['public_url'] ?? ''),
                        (string) ($row['target_url'] ?? ''),
                        (string) ($row['status_label'] ?? ''),
                        (string) ($row['notes'] ?? ''),
                        !empty($row['is_conflict']) ? 'Yes' : 'No',
                    ];
                }
            })($rows),
            ['Type', 'Title', 'Public URL', 'Target URL', 'Status', 'Notes', 'Conflict']
        );
    }

    /**
     * Returns the taxonomy lookup parser on first use.
     *
     * @return TaxonomyRepoParser Taxonomy lookup parser.
     */
    private function taxonomyLookupRepo(): TaxonomyRepoParser
    {
        if ($this->taxonomyLookupRepo instanceof TaxonomyRepoParser) {
            return $this->taxonomyLookupRepo;
        }

        $taxonomyLookupRepo = ($this->taxonomyLookupRepoResolver)();
        if (!$taxonomyLookupRepo instanceof TaxonomyRepoParser) {
            throw new \RuntimeException('Panel taxonomy lookup parser resolver returned an invalid value.');
        }

        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        return $this->taxonomyLookupRepo;
    }

    /**
     * Returns routing-inventory taxonomy data while skipping taxonomy lookup parsing
     * entirely when both category and tag public routes are disabled.
     *
     * @param string $categoryPrefix Effective category route prefix.
     * @param string $tagPrefix Effective tag route prefix.
     * @return array{
     *   channel_options: array<int, array<string, mixed>>,
     *   category_options_all: array<int, array<string, mixed>>,
     *   tag_options_all: array<int, array<string, mixed>>,
     *   redirect_rows: array<int, array<string, mixed>>
     * }
     */
    private function routingInventoryTaxonomyOptionSets(string $categoryPrefix, string $tagPrefix): array
    {
        $includeCategories = trim($categoryPrefix) !== '';
        $includeTags = trim($tagPrefix) !== '';
        if (!$includeCategories && !$includeTags) {
            return [
                'channel_options' => $this->channelRepo->listRoutingOptions(),
                'category_options_all' => [],
                'tag_options_all' => [],
                'redirect_rows' => $this->redirectRepo->listAll(),
            ];
        }

        return $this->taxonomyLookupRepo()->listRoutingInventoryData($includeCategories, $includeTags, true);
    }

    /**
     * Returns the configured global page route mode.
     *
     * @return string 'slug' or 'id'; reflects the `content.mode` config key.
     */
    private function globalPageRouteMode(): string
    {
        return ChannelRouteParser::globalPageRouteMode($this->config);
    }

    /**
     * Returns the effective page route mode for one channel, resolving `inherit` against site config.
     *
     * @param string $channelValue Per-channel route-mode value from the channel record.
     * @return string Concrete route-mode key used for URL lookups and path generation.
     */
    private function effectiveChannelRouteMode(string $channelValue): string
    {
        return ChannelRouteParser::effectiveChannelRouteMode($this->config, $channelValue);
    }

    /**
     * Normalizes one persisted/user-submitted identifier column value.
     *
     * Accepts canonical usernames and email-shaped values.
     *
     * @param string $rawValue Raw identifier value.
     * @return string|null Normalized identifier or null when invalid.
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        return $this->identifierResolver->normalizeUsernameOrEmail($this->input, $rawValue);
    }

    /**
     * Returns one public profile route segment for a user row.
     *
     * @param array<string, mixed> $user User row used for routing inventory.
     * @return string|null Public route segment or null when unavailable.
     */
    private function publicProfileRouteSegmentForUser(array $user): ?string
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        return match ($this->groupParser()->profileSelector()) {
            'string' => $this->currentUserString($user),
            'username' => $this->normalizeUserIdentifierValue((string) ($user['username'] ?? '')),
            default => (string) $userId,
        };
    }

    /**
     * Returns the current persisted user string when available.
     *
     * @param array<string, mixed>|null $user User row or null.
     * @return string|null Persisted user string.
     */
    private function currentUserString(?array $user): ?string
    {
        $userString = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) ($user['string'] ?? ''))) ?? '';
        return $userString !== '' ? $userString : null;
    }

    /**
     * Builds panel-visible routing inventory rows for feed/page/channel/category/tag/redirect/user/group.
     *
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
     * }>
     */
    private function routingRowsForPanel(): array
    {
        $categoryPrefix = $this->categoryRoutePrefix();
        $tagPrefix = $this->tagRoutePrefix();
        $profilePrefix = $this->profileRoutePrefix();
        $profileRoutesEnabled = $this->profileRoutesEnabledForRoutingTable();
        $groupPrefix = $this->groupRoutePrefix();
        $groupRoutesEnabled = $this->groupRoutesEnabledForRoutingTable();

        $groupRoutingEnabled = $groupRoutesEnabled && $groupPrefix !== '';
        $userRoutingEnabled = $profileRoutesEnabled && $profilePrefix !== '';
        $routingAuthData = $this->userRepo->listRoutingData($groupRoutingEnabled, $userRoutingEnabled);
        $routingGroups = is_array($routingAuthData['group_rows'] ?? null) ? $routingAuthData['group_rows'] : [];
        $routingUsers = is_array($routingAuthData['user_rows'] ?? null) ? $routingAuthData['user_rows'] : [];
        $taxonomyRoutingOptionSets = $this->routingInventoryTaxonomyOptionSets($categoryPrefix, $tagPrefix);

        return $this->routeProfiler()->buildRows([
            'reserved_prefixes' => $this->reservedPublicPrefixes(),
            'channel_index_template_exists' => $this->channelIndexTemplateExistsForRouting(),
            'feed_enabled' => $this->feedParser()->feedEnabled(),
            'rss_feed_route' => $this->feedParser()->rssFeedRoute(),
            'atom_feed_route' => $this->feedParser()->atomFeedRoute(),
            'category_prefix' => $categoryPrefix,
            'tag_prefix' => $tagPrefix,
            'profile_prefix' => $profilePrefix,
            'profile_routes_enabled' => $profileRoutesEnabled,
            'group_prefix' => $groupPrefix,
            'group_routes_enabled' => $groupRoutesEnabled,
            'routing_groups' => $routingGroups,
            'routing_users' => $routingUsers,
            'channel_routing_options' => is_array($taxonomyRoutingOptionSets['channel_options'] ?? null)
                ? $taxonomyRoutingOptionSets['channel_options']
                : [],
            'category_routing_options' => is_array($taxonomyRoutingOptionSets['category_options_all'] ?? null)
                ? $taxonomyRoutingOptionSets['category_options_all']
                : [],
            'tag_routing_options' => is_array($taxonomyRoutingOptionSets['tag_options_all'] ?? null)
                ? $taxonomyRoutingOptionSets['tag_options_all']
                : [],
            'redirect_routing_rows' => is_array($taxonomyRoutingOptionSets['redirect_rows'] ?? null)
                ? $taxonomyRoutingOptionSets['redirect_rows']
                : [],
            'pages_for_routing' => $this->pageRepo->listAllForRouting(),
            'build_page_url' => fn (
                string $pageSlug,
                int $pageId,
                string $channelSlug,
                string $publishedAt,
                string $channelPageRouteMode,
                string $channelPageUrlSeparator
            ): string => $this->routingPublicPathForPage(
                $pageSlug,
                $pageId,
                $channelSlug,
                $publishedAt,
                $channelPageRouteMode,
                $channelPageUrlSeparator
            ),
            'channel_landing_map_builder' => fn (array $pagesForRouting): array => $this->channelLandingMapFromPagesForRouting($pagesForRouting),
            'build_edit_url' => fn (string $typeKey, array $meta): string => $this->routingEditUrl($typeKey, $meta),
            'build_user_route_segment' => fn (array $user): ?string => $this->publicProfileRouteSegmentForUser($user),
            'slugify_group_name' => fn (string $name): string => $this->slugifyGroupName($name),
        ]);
    }

    /**
     * Builds one panel edit URL for a routing row type when the current user can edit that source.
     *
     * @param string $typeKey Routing row type key.
     * @param array<string, mixed> $meta Optional source metadata emitted by RouteProfiler.
     * @return string Panel edit URL or empty string when no editable target is available.
     */
    private function routingEditUrl(string $typeKey, array $meta): string
    {
        $normalizedType = strtolower(trim($typeKey));
        $sourceId = max(0, (int) ($meta['id'] ?? 0));

        return match ($normalizedType) {
            'feed' => $this->routingFeedEditUrl($meta),
            'channel' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::CHANNELS_EDIT)
                ? $this->context->panelUrl('/channel/edit/' . $sourceId)
                : '',
            'page' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::PAGES_EDIT)
                ? $this->context->panelUrl('/page/edit/' . $sourceId)
                : '',
            'category' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::CATEGORIES_EDIT)
                ? $this->context->panelUrl('/category/edit/' . $sourceId)
                : '',
            'tag' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::TAGS_EDIT)
                ? $this->context->panelUrl('/tag/edit/' . $sourceId)
                : '',
            'group' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::GROUPS_EDIT)
                ? $this->context->panelUrl('/group/edit/' . $sourceId)
                : '',
            'user' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::USERS_EDIT)
                ? $this->context->panelUrl('/user/edit/' . $sourceId)
                : '',
            'redirect' => $sourceId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::REDIRECTS_EDIT)
                ? $this->context->panelUrl('/redirect/edit/' . $sourceId)
                : '',
            default => '',
        };
    }

    /**
     * Builds one panel edit URL for a feed routing row.
     *
     * @param array<string, mixed> $meta Optional source metadata emitted by RouteProfiler.
     * @return string Panel edit URL or empty string when feed source is not editable by current user.
     */
    private function routingFeedEditUrl(array $meta): string
    {
        $kind = strtolower(trim((string) ($meta['kind'] ?? '')));
        if ($kind === 'global') {
            return $this->context->auth()->canManageConfiguration()
                ? $this->context->panelUrl('/configuration?tab=content')
                : '';
        }

        $channelId = max(0, (int) ($meta['channel_id'] ?? 0));
        if ($kind === 'channel' && $channelId > 0 && $this->context->auth()->hasPanelPermissionBit(PanelAccess::CHANNELS_EDIT)) {
            return $this->context->panelUrl('/channel/edit/' . $channelId);
        }

        return '';
    }

    /**
     * Builds one routing-table public URL path for a page row.
     */
    private function routingPublicPathForPage(
        string $pageSlug,
        int $pageId,
        string $channelSlug,
        string $publishedAt,
        string $routeModeEffective,
        string $routeSeparatorEffective
    ): string {
        return $this->panelRoutingPreviewService()->routingPublicPathForPage(
            $pageSlug,
            $pageId,
            $channelSlug,
            $publishedAt,
            $channelSlug === ''
                ? $this->globalPageRouteMode()
                : $this->effectiveChannelRouteMode($routeModeEffective),
            $routeSeparatorEffective,
            (string) $this->config->get('content.separator', '-')
        );
    }

    /**
     * Derives one channel -> landing page slug map from routing page rows.
     *
     * @param array<int, array<string, mixed>> $pagesForRouting Page rows used for routing inventory.
     * @return array<string, string> Channel slug to landing page slug map.
     */
    private function channelLandingMapFromPagesForRouting(array $pagesForRouting): array
    {
        return $this->panelRoutingPreviewService()->channelLandingMapFromPages($pagesForRouting);
    }

    /**
     * Returns true when the public channel index template resolves in the active theme chain or core fallback.
     */
    private function channelIndexTemplateExistsForRouting(): bool
    {
        return $this->panelRoutingPreviewService()->channelIndexTemplateExists($this->config);
    }

    /**
     * Returns reserved root/channel slugs blocked by public router prefixes.
     *
     * @return array<int, string> Reserved public prefixes.
     */
    private function reservedPublicPrefixes(): array
    {
        return $this->panelRoutingPreviewService()->reservedPublicPrefixes(
            (string) $this->config->get('panel.path', 'panel'),
            [
                $this->categoryRoutePrefix(),
                $this->tagRoutePrefix(),
                $this->profileRoutePrefix(),
                $this->groupRoutePrefix(),
            ]
        );
    }

    /**
     * Returns the configured public category route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        return CategoryRouteParser::categoryRoutePrefix($this->config, $this->input);
    }

    /**
     * Returns the configured public tag route prefix.
     */
    private function tagRoutePrefix(): string
    {
        return TagRouteParser::tagRoutePrefix($this->config, $this->input);
    }

    /**
     * Returns the configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->groupParser()->profileRoutePrefix();
    }

    /**
     * Returns true when public profile URLs are enabled for routing inventory.
     */
    private function profileRoutesEnabledForRoutingTable(): bool
    {
        return $this->groupParser()->profileRoutesEnabledForRoutingTable();
    }

    /**
     * Returns the configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->groupParser()->groupRoutePrefix();
    }

    /**
     * Returns true when public group URLs are enabled for routing inventory.
     */
    private function groupRoutesEnabledForRoutingTable(): bool
    {
        return $this->groupParser()->groupRoutesEnabledForRoutingTable();
    }

    /**
     * Derives one stable URL slug from a group name.
     */
    private function slugifyGroupName(string $groupName): string
    {
        $slug = $this->input->slug($groupName);
        if ($slug === null || $slug === '') {
            return '';
        }

        return $slug;
    }

    /**
     * Returns the feed route parser on first use.
     */
    private function feedParser(): FeedRouteParser
    {
        if (!$this->feedParser instanceof FeedRouteParser) {
            $this->feedParser = new FeedRouteParser($this->config, $this->input);
        }

        return $this->feedParser;
    }

    /**
     * Returns the group route parser on first use.
     */
    private function groupParser(): GroupRouteParser
    {
        if (!$this->groupParser instanceof GroupRouteParser) {
            $this->groupParser = new GroupRouteParser($this->config, $this->input);
        }

        return $this->groupParser;
    }

    /**
     * Returns the routing-inventory builder on first use.
     */
    private function routeProfiler(): RouteProfiler
    {
        if (!$this->routeProfiler instanceof RouteProfiler) {
            $this->routeProfiler = new RouteProfiler($this->input);
        }

        return $this->routeProfiler;
    }

    /**
     * Returns the canonical CSV import/export helper.
     */
    private function csvHandler(): Csv
    {
        if (!$this->csvHandler instanceof Csv) {
            $this->csvHandler = new Csv();
        }

        return $this->csvHandler;
    }

    /**
     * Returns the panel routing-preview service on first use.
     */
    private function panelRoutingPreviewService(): PanelRoutingPreviewService
    {
        if (!$this->panelRoutingPreviewService instanceof PanelRoutingPreviewService) {
            $this->panelRoutingPreviewService = new PanelRoutingPreviewService(
                $this->root,
                $this->input,
                $this->themeCatalogService
            );
        }

        return $this->panelRoutingPreviewService;
    }
}
