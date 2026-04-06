<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/PublicController.php
 * Controller for handling Raven HTTP request flow.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Enforce access and input validation before delegating to lower layers.

declare(strict_types=1);

namespace Raven\Controller;

use Closure;
use Raven\Core\Auth\AuthService;
use Raven\Core\Config;
use Raven\Core\Extension\EmbeddedFormRuntimeInterface;
use Raven\Core\Extension\EmbeddedShortcodeRuntimeInterface;
use Raven\Lib\Auth\LoginAttemptPolicy;
use Raven\Lib\Auth\LoginAttemptWorkflowService;
use Raven\Lib\Auth\LoginChallengeWorkflowService;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Auth\LoginUiStateService;
use Raven\Lib\Content\BodyBlockPolicy;
use Raven\Lib\Content\MarkdownRenderer;
use Raven\Lib\Content\PublicPageBodyRenderer;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Extension\EmbeddedFormRuntimeService;
use Raven\Lib\Http\HttpResponse;
use Raven\Lib\Http\RequestContextResolver;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\PublicChannelPageRouteService;
use Raven\Lib\Http\RedirectTargetValidator;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Routing\RouteConfigService;
use Raven\Lib\Security\CaptchaService;
use Raven\Lib\Site\PublicMetaService;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\ThemeCatalogService;
use Raven\Lib\View\PublicRouteRenderService;
use Raven\Lib\View\PublicTemplateDecorator;
use Raven\Lib\View\PublicTemplatePipeline;
use Raven\Lib\View\PublicTemplateResolver;
use Raven\Core\View;
use Raven\Core\View\TemplateTagEngine;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TaxonomyLookupRepository;
use Raven\Repository\UserRepository;

/**
 * Handles public website routes.
 */
final class PublicController
{
    private View $view;
    private Config $config;
    private AuthService $auth;
    private GroupRepository $groupRepo;
    private PageImageRepository $pageImages;
    private PageRepository $pageRepo;
    private RedirectRepository $redirectRepo;
    private TaxonomyLookupRepository $taxonomyLookupRepo;
    private UserRepository $userRepo;
    private ?InviteTokenRepository $inviteTokens = null;
    private ?Closure $inviteTokensResolver = null;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $publicFlash;
    private LoginIdentifierResolver $identifierResolver;
    private ?Closure $extensionServicesProvider = null;
    /** @var array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> */
    private array $embeddedFormRuntimes = [];
    private bool $embeddedFormRuntimesLoaded = false;
    private TemplateTagEngine $templateTags;
    private bool $captchaScriptIncluded = false;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;
    private ?SiteContextBuilder $siteContextBuilder = null;
    private ?LoginUiStateService $loginUiState = null;
    private ?LoginAttemptPolicy $loginAttemptPolicy = null;
    private ?LoginAttemptWorkflowService $loginAttemptWorkflowService = null;
    private ?LoginChallengeWorkflowService $loginChallengeWorkflowService = null;
    private ?MarkdownRenderer $markdownRenderer = null;
    private ?RequestContextResolver $requestContextResolver = null;
    private ?PublicTemplateResolver $publicTemplateResolver = null;
    private ?PublicTemplatePipeline $publicTemplatePipeline = null;
    private ?EmbeddedFormRuntimeService $embeddedFormRuntimeService = null;
    private ?ProfileContactService $profileContactService = null;
    private ?RouteConfigService $routeConfigService = null;
    private ?BodyBlockPolicy $bodyBlockPolicy = null;
    private ?CaptchaService $captchaService = null;
    private ?ThemeCatalogService $themeCatalogService = null;
    private ?ExtensionEditorCatalogService $extensionEditorCatalogService = null;
    private ?PublicMetaService $publicMetaService = null;
    private ?PublicTemplateDecorator $publicTemplateDecorator = null;
    private ?PublicPageBodyRenderer $pageBodyRenderer = null;
    private ?PublicRouteRenderService $publicRouteRenderService = null;
    private ?PublicChannelPageRouteService $publicChannelPageRouteService = null;

    /**
     * Initializes the public controller with core repositories and lazy extension access.
     *
     * @param View $view Shared view renderer for public templates.
     * @param Config $config Runtime configuration reader for route and theme behavior.
     * @param AuthService $auth Auth/session service used by login and gated public helpers.
     * @param GroupRepository $groupRepo Group repository for public group-route lookups.
     * @param PageImageRepository $pageImages Page image repository for cover/gallery public rendering.
     * @param PageRepository $pageRepo Page repository for homepage/page/feed queries.
     * @param RedirectRepository $redirectRepo Redirect repository for public redirect fallbacks.
     * @param TaxonomyLookupRepository $taxonomyLookupRepo Taxonomy lookup repository for channel/category/tag route resolution.
     * @param UserRepository $userRepo User repository for registration and public profile routes.
     * @param callable(): InviteTokenRepository $inviteTokensResolver Invite-token repository resolver for invite-only registration flows.
     * @param InputSanitizer $input Shared request input sanitizer for all public actions.
     * @param Csrf $csrf CSRF helper used by public auth and embedded-form submissions.
     * @param callable(): array<string, mixed>|null $extensionServicesProvider Lazy extension-services resolver used only when embedded runtimes are needed.
     * @return void
     */
    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        GroupRepository $groupRepo,
        PageImageRepository $pageImages,
        PageRepository $pageRepo,
        RedirectRepository $redirectRepo,
        TaxonomyLookupRepository $taxonomyLookupRepo,
        UserRepository $userRepo,
        callable $inviteTokensResolver,
        InputSanitizer $input,
        Csrf $csrf,
        ?callable $extensionServicesProvider = null
    )
    {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->groupRepo = $groupRepo;
        $this->pageImages = $pageImages;
        $this->pageRepo = $pageRepo;
        $this->redirectRepo = $redirectRepo;
        $this->taxonomyLookupRepo = $taxonomyLookupRepo;
        $this->userRepo = $userRepo;
        $this->inviteTokensResolver = Closure::fromCallable($inviteTokensResolver);
        $this->input = $input;
        $this->csrf = $csrf;
        $this->publicFlash = new SessionFlash('_raven_public_flash');
        $this->identifierResolver = new LoginIdentifierResolver();
        $this->extensionServicesProvider = is_callable($extensionServicesProvider)
            ? Closure::fromCallable($extensionServicesProvider)
            : null;
        $this->templateTags = new TemplateTagEngine(dirname(__DIR__, 3) . '/.tmp/template_tag_cache');
    }

    /**
     * Returns the invite-token repository on first use so ordinary public page
     * traffic does not instantiate registration-only storage.
     *
     * @return InviteTokenRepository Invite-token repository for registration flows.
     */
    private function inviteTokens(): InviteTokenRepository
    {
        if ($this->inviteTokens instanceof InviteTokenRepository) {
            return $this->inviteTokens;
        }

        if (!$this->inviteTokensResolver instanceof Closure) {
            throw new \RuntimeException('Public invite-token repository resolver is unavailable.');
        }

        $inviteTokens = ($this->inviteTokensResolver)();
        if (!$inviteTokens instanceof InviteTokenRepository) {
            throw new \RuntimeException('Public invite-token repository resolver returned an invalid value.');
        }

        $this->inviteTokens = $inviteTokens;
        return $this->inviteTokens;
    }

    /**
     * Renders homepage using `home` slug or `index` fallback, outside channels.
     */
    public function home(): void
    {
        $page = $this->pageRepo->findHomepage();

        if ($page === null) {
            $this->notFound();
            return;
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->decoratePageForTemplate($page);

        $this->renderPublic('home', [
            'site' => $this->siteDataWithPageMeta($page),
            'page' => $page,
        ], 'wrapper');
    }

    /**
     * Resolves one channel landing route by channel slug.
     *
     * Landing selection mirrors homepage priority inside the channel:
     * `home` first, then `index`.
     *
     * If no channel landing page is available, fallback preserves existing
     * single-segment behavior (root page + redirect lookup).
     */
    public function channel(string $channelSlug): void
    {
        $page = $this->pageRepo->findChannelHomepage($channelSlug);

        if ($page === null) {
            $this->page($channelSlug, null);
            return;
        }

        $channel = $this->taxonomyLookupRepo->findChannelBySlug($channelSlug);

        $page = $this->renderPageContentBlocks($page);
        $page = $this->decoratePageForTemplate($page);

        $channelTemplate = $this->publicTemplatePipeline()->resolveChannelTemplateNameForThemeChain(
            $channelSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 3) . '/private/tpl'
        );
        $site = $this->siteDataWithPageMeta($page);
        if (is_array($channel)) {
            // Channel-level cover/preview uploads override default/page fallback for channel landing routes.
            $site = $this->siteDataWithTaxonomyMetaImage($channel, $site);
        }

        $this->renderPublic($channelTemplate, [
            'site' => $site,
            'channel' => is_array($channel) ? $channel : null,
            'page' => $page,
        ], 'wrapper');
    }

    /**
     * Renders one public page, optionally nested by channel slug.
     */
    public function page(string $pageSlug, ?string $channelSlug = null): void
    {
        $requestedSlug = strtolower(trim($pageSlug));
        $lookupSlug = $requestedSlug;
        $lookupTarget = null;
        $channel = null;
        $channelRouteMode = 'slug';
        $channelWordSeparator = 'inherit';

        if ($channelSlug !== null) {
            $channel = $this->taxonomyLookupRepo->findChannelBySlug($channelSlug);
            if ($channel === null) {
                if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                    return;
                }

                $this->notFound();
                return;
            }

            $channelRouteMode = $this->effectiveChannelRouteMode((string) ($channel['route_mode'] ?? 'inherit'));
            $channelWordSeparator = $this->publicChannelPageRouteService()->resolveWordSeparator(
                (string) ($channel['route_separator'] ?? 'inherit'),
                (string) $this->config->get('content.separator', '-')
            );

            $lookupTarget = $this->publicChannelPageRouteService()->resolveLookupTarget(
                $requestedSlug,
                $channelRouteMode,
                $channelWordSeparator
            );
            if (!is_array($lookupTarget)) {
                if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                    return;
                }

                $this->notFound();
                return;
            }

            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        } else {
            $channelRouteMode = $this->globalPageRouteMode();
            $lookupTarget = $this->publicChannelPageRouteService()->resolveLookupTarget(
                $requestedSlug,
                $channelRouteMode,
                (string) $this->config->get('content.separator', '-')
            );
            if (!is_array($lookupTarget)) {
                if ($this->tryRedirect($requestedSlug, null)) {
                    return;
                }

                $this->notFound();
                return;
            }

            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        }

        $page = null;
        if (is_array($lookupTarget ?? null) && (string) ($lookupTarget['type'] ?? '') === 'id') {
            $page = $this->pageRepo->findPublicPageById((int) ($lookupTarget['id'] ?? 0), $channelSlug);
        } else {
            $page = $this->pageRepo->findPublicPage($lookupSlug, $channelSlug);
        }

        if ($page === null) {
            // If no page exists at this path, attempt redirect fallback before 404.
            if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                return;
            }

            $this->notFound();
            return;
        }

        $canonicalSegment = $this->publicChannelPageRouteService()->canonicalSegment(
            (string) ($page['slug'] ?? ''),
            (int) ($page['id'] ?? 0),
            (string) ($page['created'] ?? ''),
            $channelRouteMode,
            $channelWordSeparator,
            (string) $this->config->get('content.separator', '-')
        );
        if ($channelSlug !== null) {
            if ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
                \Raven\Core\Support\redirect(
                    '/' . rawurlencode($channelSlug) . '/' . rawurlencode($canonicalSegment),
                    301
                );
            }
        } elseif ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
            \Raven\Core\Support\redirect('/' . rawurlencode($canonicalSegment), 301);
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->decoratePageForTemplate($page);

        $pageTemplate = $this->publicTemplatePipeline()->resolvePageTemplateNameForThemeChain(
            $channelSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 3) . '/private/tpl'
        );

        $this->renderPublic($pageTemplate, [
            'site' => $this->siteDataWithPageMeta($page),
            'channel' => is_array($channel) ? $channel : null,
            'page' => $page,
        ], 'wrapper');
    }

    /**
     * Builds public URL paths for category/tag page-list rows.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function decoratePageListPublicPaths(array $pages): array
    {
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $slug = $this->input->slug((string) ($page['slug'] ?? ''));
            $pageId = (int) ($page['id'] ?? 0);
            if ($slug === null || $slug === '') {
                $pages[$index]['url'] = '/';
                continue;
            }

            $channelSlug = $this->input->slug((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === null || $channelSlug === '') {
                $rootSegment = $this->publicChannelPageRouteService()->canonicalSegment(
                    $slug,
                    $pageId,
                    (string) ($page['created'] ?? ''),
                    $this->globalPageRouteMode(),
                    'inherit',
                    (string) $this->config->get('content.separator', '-')
                );
                $pages[$index]['url'] = '/' . rawurlencode($rootSegment !== '' ? $rootSegment : $slug);
                continue;
            }

            $pages[$index]['url'] = '/'
                . rawurlencode($channelSlug)
                . '/'
                . rawurlencode(
                    $this->publicChannelPageRouteService()->canonicalSegment(
                        $slug,
                        $pageId,
                        (string) ($page['created'] ?? ''),
                        $this->effectiveChannelRouteMode((string) ($page['route_mode_effective'] ?? 'inherit')),
                        (string) ($page['route_separator_effective'] ?? 'inherit'),
                        (string) $this->config->get('content.separator', '-')
                    )
                );
        }

        return $pages;
    }

    /**
     * Prepares page-list rows for template tags.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function decoratePageListForTemplate(array $pages): array
    {
        return $this->publicTemplateDecorator()->decoratePageListForTemplate($pages);
    }

    /**
     * Renders one configured feed response as XML without the HTML wrapper.
     */
    private function renderFeed(string $format, ?string $channelSlug = null): void
    {
        $routeConfig = $this->routeConfigService();
        if (!$routeConfig->feedEnabled()) {
            $this->notFound();
            return;
        }

        $routeSegment = $format === 'atom' ? $routeConfig->atomFeedRoute() : $routeConfig->rssFeedRoute();
        if ($routeSegment === '') {
            $this->notFound();
            return;
        }

        $site = $this->siteData();
        $feedChannelSlug = '';
        $configuredFeedChannels = $routeConfig->feedChannels();
        $scopeLabel = '';
        $scopeType = 'global';
        $scopeSlug = '';
        $pages = [];
        if ($channelSlug !== null) {
            $normalizedChannelSlug = strtolower(trim($channelSlug));
            if ($normalizedChannelSlug === '') {
                $this->notFound();
                return;
            }

            $channel = $this->taxonomyLookupRepo->findChannelBySlug($normalizedChannelSlug);
            if (!is_array($channel) || !$this->channelFeedEnabled($channel)) {
                $this->notFound();
                return;
            }

            $feedChannelSlug = $normalizedChannelSlug;
            $scopeLabel = $this->feedChannelLabel($feedChannelSlug);
            $scopeType = 'channel';
            $scopeSlug = $feedChannelSlug;
            $pages = $this->pageRepo->listRecentPublished($routeConfig->feedItems(), $feedChannelSlug);
        } else {
            if (in_array('all', $configuredFeedChannels, true)) {
                $pages = $this->pageRepo->listRecentPublished($routeConfig->feedItems(), null);
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

                $pages = $this->pageRepo->listRecentPublishedForChannels(
                    $routeConfig->feedItems(),
                    $selectedFeedChannels
                );
            }

            if ($feedChannelSlug !== '') {
                $scopeType = 'channel';
            }
        }

        $feedPayload = $this->buildFeedPayload(
            $format,
            $this->buildFeedRoutePath($routeSegment, $channelSlug !== null && $feedChannelSlug !== '' ? [$feedChannelSlug] : []),
            $scopeLabel,
            $site,
            $pages,
            $scopeType,
            $scopeSlug
        );

        header(
            'Content-Type: ' . ($format === 'atom' ? 'application/atom+xml' : 'application/rss+xml') . '; charset=UTF-8'
        );
        $this->renderPublic('feeds/' . $format, [
            'site' => $site,
            'feed' => $feedPayload,
            'pages' => $feedPayload['items'],
        ], null);
    }

    private function renderTaxonomyFeed(string $format, string $taxonomyType, string $taxonomySlug): void
    {
        $routeConfig = $this->routeConfigService();
        if (!$routeConfig->feedEnabled()) {
            $this->notFound();
            return;
        }

        $routeSegment = $format === 'atom' ? $routeConfig->atomFeedRoute() : $routeConfig->rssFeedRoute();
        if ($routeSegment === '') {
            $this->notFound();
            return;
        }

        $normalizedSlug = strtolower(trim($taxonomySlug));
        if ($normalizedSlug === '') {
            $this->notFound();
            return;
        }

        $site = $this->siteData();
        $scopeLabel = '';
        $routeSuffix = [];
        $pages = [];

        if ($taxonomyType === 'category') {
            $categoryPrefix = $routeConfig->categoryRoutePrefix();
            if ($categoryPrefix === '') {
                $this->notFound();
                return;
            }

            $category = $this->taxonomyLookupRepo->findCategoryBySlug($normalizedSlug);
            if (!is_array($category)) {
                $this->notFound();
                return;
            }

            $pageResult = $this->pageRepo->listPageByCategorySlug($normalizedSlug, $routeConfig->feedItems(), 0);
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $scopeLabel = $this->taxonomyFeedLabel($category, $normalizedSlug);
            $routeSuffix = [$categoryPrefix, $normalizedSlug];
        } elseif ($taxonomyType === 'tag') {
            $tagPrefix = $routeConfig->tagRoutePrefix();
            if ($tagPrefix === '') {
                $this->notFound();
                return;
            }

            $tag = $this->taxonomyLookupRepo->findTagBySlug($normalizedSlug);
            if (!is_array($tag)) {
                $this->notFound();
                return;
            }

            $pageResult = $this->pageRepo->listPageByTagSlug($normalizedSlug, $routeConfig->feedItems(), 0);
            $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
            $scopeLabel = $this->taxonomyFeedLabel($tag, $normalizedSlug);
            $routeSuffix = [$tagPrefix, $normalizedSlug];
        } else {
            $this->notFound();
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

        header(
            'Content-Type: ' . ($format === 'atom' ? 'application/atom+xml' : 'application/rss+xml') . '; charset=UTF-8'
        );
        $this->renderPublic('feeds/' . $format, [
            'site' => $site,
            'feed' => $feedPayload,
            'pages' => $feedPayload['items'],
        ], null);
    }

    /**
     * @param array<string, string> $site
     * @param array<int, array<string, mixed>> $pages
     * @return array<string, mixed>
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
     * @param array<int, array<string, mixed>> $pages
     * @param array<string, string> $site
     * @return array<int, array<string, mixed>>
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

    private function feedChannelLabel(string $channelSlug): string
    {
        $normalized = strtolower(trim($channelSlug));
        if ($normalized === '') {
            return 'All Channels';
        }

        if ($normalized === 'root') {
            return 'Root';
        }

        $channel = $this->taxonomyLookupRepo->findChannelBySlug($normalized);
        if (!is_array($channel)) {
            return $normalized;
        }

        $name = trim((string) ($channel['name'] ?? ''));
        return $name !== '' ? $name : $normalized;
    }

    /**
     * Returns true when one channel explicitly allows channel-specific feed routes.
     *
     * @param array<string, mixed> $channel
     */
    private function channelFeedEnabled(array $channel): bool
    {
        return (bool) ($channel['feed_enabled'] ?? false);
    }

    /**
     * @param array<string, mixed> $taxonomy
     */
    private function taxonomyFeedLabel(array $taxonomy, string $fallbackSlug): string
    {
        $name = trim((string) ($taxonomy['name'] ?? ''));
        return $name !== '' ? $name : $fallbackSlug;
    }

    /**
     * @param array<int, string> $extraSegments
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
     * Attempts active redirect lookup for a URL path and emits HTTP redirect when found.
     */
    private function tryRedirect(string $pageSlug, ?string $channelSlug = null): bool
    {
        $redirect = $this->redirectRepo->findActiveByPath($pageSlug, $channelSlug);
        if ($redirect === null) {
            return false;
        }

        $targetUrl = trim((string) ($redirect['target'] ?? ''));
        if (!RedirectTargetValidator::isAllowedHttpOrRootPath($targetUrl)) {
            return false;
        }

        // Default behavior is temporary redirect; status configuration can be added later.
        \Raven\Core\Support\redirect($targetUrl, 302);
        return true;
    }

    /**
     * Renders category listing route `/{category_prefix}/{category_slug}/{page?}`.
     */
    public function category(string $categorySlug, int $pageNumber = 1): void
    {
        $categoryPrefix = $this->routeConfigService()->categoryRoutePrefix();
        if ($categoryPrefix === '') {
            $this->notFound();
            return;
        }

        $category = $this->taxonomyLookupRepo->findCategoryBySlug($categorySlug);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $perPage = max(1, (int) $this->config->get('category.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageRepo->listPageByCategorySlug($categorySlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($total > 0 && $pageNumber > $totalPages) {
            $this->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pages = $this->decoratePageListPublicPaths($pages);
        $pages = $this->decoratePageListForTemplate($pages);
        $pagination = [
            'current' => $pageNumber,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_path' => '/' . $categoryPrefix . '/' . rawurlencode($categorySlug),
        ];
        $pagination = $this->decoratePaginationForTemplate($pagination);
        $categoryTemplate = $this->publicTemplatePipeline()->resolveCategoryTemplateNameForThemeChain(
            $categorySlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 3) . '/private/tpl'
        );

        $this->renderPublic($categoryTemplate, [
            'site' => $this->siteDataWithTaxonomyMetaImage($category),
            'category' => $category,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Renders tag listing route `/{tag_prefix}/{tag_slug}/{page?}`.
     */
    public function tag(string $tagSlug, int $pageNumber = 1): void
    {
        $tagPrefix = $this->routeConfigService()->tagRoutePrefix();
        if ($tagPrefix === '') {
            $this->notFound();
            return;
        }

        $tag = $this->taxonomyLookupRepo->findTagBySlug($tagSlug);

        if ($tag === null) {
            $this->notFound();
            return;
        }

        $perPage = max(1, (int) $this->config->get('tag.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageRepo->listPageByTagSlug($tagSlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($total > 0 && $pageNumber > $totalPages) {
            $this->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pages = $this->decoratePageListPublicPaths($pages);
        $pages = $this->decoratePageListForTemplate($pages);
        $pagination = [
            'current' => $pageNumber,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_path' => '/' . $tagPrefix . '/' . rawurlencode($tagSlug),
        ];
        $pagination = $this->decoratePaginationForTemplate($pagination);
        $tagTemplate = $this->publicTemplatePipeline()->resolveTagTemplateNameForThemeChain(
            $tagSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 3) . '/private/tpl'
        );

        $this->renderPublic($tagTemplate, [
            'site' => $this->siteDataWithTaxonomyMetaImage($tag),
            'tag' => $tag,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Renders RSS feed route `/{feed.rss}` when feeds are enabled.
     */
    public function rssFeed(?string $channelSlug = null): void
    {
        $this->renderFeed('rss', $channelSlug);
    }

    /**
     * Renders RSS category feed route `/{feed.rss}/{category.prefix}/{category_slug}` when enabled.
     */
    public function rssCategoryFeed(string $categorySlug): void
    {
        $this->renderTaxonomyFeed('rss', 'category', $categorySlug);
    }

    /**
     * Renders RSS tag feed route `/{feed.rss}/{tag.prefix}/{tag_slug}` when enabled.
     */
    public function rssTagFeed(string $tagSlug): void
    {
        $this->renderTaxonomyFeed('rss', 'tag', $tagSlug);
    }

    /**
     * Renders Atom feed route `/{feed.atom}` when feeds are enabled.
     */
    public function atomFeed(?string $channelSlug = null): void
    {
        $this->renderFeed('atom', $channelSlug);
    }

    /**
     * Renders Atom category feed route `/{feed.atom}/{category.prefix}/{category_slug}` when enabled.
     */
    public function atomCategoryFeed(string $categorySlug): void
    {
        $this->renderTaxonomyFeed('atom', 'category', $categorySlug);
    }

    /**
     * Renders Atom tag feed route `/{feed.atom}/{tag.prefix}/{tag_slug}` when enabled.
     */
    public function atomTagFeed(string $tagSlug): void
    {
        $this->renderTaxonomyFeed('atom', 'tag', $tagSlug);
    }

    /**
     * Renders one public profile route `/{profile_prefix}/{selector}`.
     */
    public function profile(string $username): void
    {
        $profileMode = $this->routeConfigService()->profileMode();
        $isLoggedIn = $this->auth->isLoggedIn();
        if ($this->routeConfigService()->profileRoutePrefix() === '') {
            $this->notFound();
            return;
        }

        if ($profileMode === 'disabled') {
            $this->renderProfileUnavailable('not_found', 'disabled');
            return;
        }

        if ($profileMode === 'private' && !$isLoggedIn) {
            $this->renderProfileUnavailable('permission_denied', 'private');
            return;
        }

        $profile = $this->findPublicProfileByRouteSegment(rawurldecode($username));
        if ($profile === null) {
            $this->notFound();
            return;
        }
        $profile = $this->decoratePublicProfileContacts($profile);
        $profile = $this->decorateProfileForTemplate($profile);

        $template = match ($profileMode) {
            'public_full' => 'profile/full',
            'public_limited' => $isLoggedIn ? 'profile/full' : 'profile/limited',
            'private' => 'profile/full',
            default => 'profile/index',
        };

        $this->renderPublic($template, [
            'site' => $this->siteData(),
            'profile' => $profile,
        ], 'wrapper');
    }

    /**
     * Renders one public group route `/{group_prefix}/{group_slug}`.
     */
    public function group(string $groupSlug): void
    {
        $groupMode = $this->routeConfigService()->groupMode();
        $isLoggedIn = $this->auth->isLoggedIn();
        if ($this->routeConfigService()->groupRoutePrefix() === '') {
            $this->notFound();
            return;
        }

        if ($groupMode === 'disabled') {
            $this->renderGroupUnavailable('not_found', 'disabled');
            return;
        }

        if ($groupMode === 'private' && !$isLoggedIn) {
            $this->renderGroupUnavailable('permission_denied', 'private');
            return;
        }

        $normalizedSlug = $this->input->slug($groupSlug);
        if ($normalizedSlug === null) {
            $this->notFound();
            return;
        }

        $groupRouteData = $this->groupRepo->findPublicRouteDataBySlug($normalizedSlug);
        if ($groupRouteData === null) {
            $this->notFound();
            return;
        }

        $group = is_array($groupRouteData['group'] ?? null) ? $groupRouteData['group'] : [];
        $members = is_array($groupRouteData['members'] ?? null) ? $groupRouteData['members'] : [];
        $members = $this->decorateGroupMembersForTemplate($members);
        $group = $this->decorateGroupForTemplate($group, $members);
        $template = match ($groupMode) {
            'public_full' => 'group/list',
            'public_limited' => $isLoggedIn ? 'group/list' : 'group/limited',
            'private' => 'group/list',
            default => 'group/index',
        };

        $this->renderPublic($template, [
            'site' => $this->siteData(),
            'group' => $group,
            'members' => $members,
        ], 'wrapper');
    }

    /**
     * Renders public login helper page.
     */
    public function login(): void
    {
        $redirectPath = $this->publicPostLoginRedirectFromRequest();
        if ($this->auth->isLoggedIn() && $this->auth->isTwoFactorVerifiedForUser()) {
            \Raven\Core\Support\redirect($redirectPath);
        }

        if ($this->auth->pendingTwoFactorUserId() !== null) {
            $this->storePublicPostLoginRedirect($redirectPath);
            \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($redirectPath));
        }

        $loginIdentifierMode = $this->identifierResolver->modeFromConfig($this->config);
        $this->renderPublic('auth/login', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullPublicFlash('success'),
            'flashError' => $this->pullPublicFlash('error'),
            'loginPath' => $this->loginPathWithRedirect($redirectPath),
            'registrationPath' => '/register',
            'registrationMode' => $this->routeConfigService()->registrationMode(),
            'loginIdentifierMode' => $loginIdentifierMode,
            'loginIdentifierLabel' => $loginIdentifierMode === 'email' ? 'Email' : 'Username or Email',
            'postLoginRedirectPath' => $redirectPath,
        ], 'wrapper');
    }

    /**
     * Processes public login form submission.
     *
     * @param array<string, mixed> $post
     */
    public function loginSubmit(array $post): void
    {
        $requestedRedirect = $this->publicPostLoginRedirectFromValue((string) ($post['redirect_to'] ?? ''));
        $this->storePublicPostLoginRedirect($requestedRedirect);

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flashPublic('error', 'Invalid CSRF token.');
            \Raven\Core\Support\redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        $result = $this->loginAttemptWorkflowService()->attempt(
            $this->auth,
            $post,
            $_SERVER,
            $this->loginUiState()
        );

        if (($result['status'] ?? '') === 'two_factor_required') {
            \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'verified') {
            \Raven\Core\Support\redirect($this->consumePublicPostLoginRedirectOrDefault());
        }

        if (($result['status'] ?? '') === 'missing_user') {
            $this->auth->logout();
            $this->clearPublicPostLoginRedirect();
        }

        $this->flashPublic('error', (string) ($result['message'] ?? 'Login failed.'));
        \Raven\Core\Support\redirect($this->loginPathWithRedirect($requestedRedirect));
    }

    /**
     * Renders public login-time 2FA challenge.
     */
    public function loginTwoFactor(): void
    {
        $redirectPath = $this->publicPostLoginRedirectFromRequest();
        if ($redirectPath !== '/') {
            $this->storePublicPostLoginRedirect($redirectPath);
        }

        $viewState = $this->loginChallengeWorkflowService()->buildViewState($this->auth, $this->loginUiState());
        if (!(bool) ($viewState['ok'] ?? false)) {
            $this->auth->logout();
            $this->clearPublicPostLoginRedirect();
            $this->flashPublic('error', (string) ($viewState['message'] ?? 'Your login session expired. Please log in again.'));
            \Raven\Core\Support\redirect($this->loginPathWithRedirect($redirectPath));
        }

        $this->renderPublic('auth/login_2fa', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'csrfToken' => $this->csrf->token(),
            'success' => $this->pullPublicFlash('success'),
            'error' => $this->pullPublicFlash('error'),
            'verifyPath' => $this->loginTwoFactorPathWithRedirect($redirectPath),
            'selectPath' => $this->loginTwoFactorSelectPathWithRedirect($redirectPath),
            'webauthnOptionsPath' => '/login/2fa/webauthn/options',
            'webauthnVerifyPath' => '/login/2fa/webauthn/verify',
            'loginPath' => $this->loginPathWithRedirect($redirectPath),
            'postLoginRedirectPath' => $redirectPath,
        ] + $viewState, 'wrapper');
    }

    /**
     * Verifies public login-time 2FA challenge.
     *
     * @param array<string, mixed> $post
     */
    public function loginTwoFactorSubmit(array $post): void
    {
        $requestedRedirect = $this->publicPostLoginRedirectFromValue((string) ($post['redirect_to'] ?? ''));
        $this->storePublicPostLoginRedirect($requestedRedirect);

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flashPublic('error', 'Invalid CSRF token.');
            \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        $result = $this->loginChallengeWorkflowService()->verifyCodeChallenge($this->auth, $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->auth->logout();
            $this->clearPublicPostLoginRedirect();
            $this->flashPublic('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            \Raven\Core\Support\redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'email_sent') {
            $this->flashPublic('success', (string) ($result['message'] ?? 'Check your email for a verification code.'));
            \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'unsupported') {
            $this->auth->logout();
            $this->clearPublicPostLoginRedirect();
            $this->flashPublic('error', 'This verification method is not supported in the public login form.');
            \Raven\Core\Support\redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') !== 'verified') {
            $this->flashPublic('error', (string) ($result['message'] ?? 'Verification failed.'));
            \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        \Raven\Core\Support\redirect($this->consumePublicPostLoginRedirectOrDefault());
    }

    /**
     * Selects one pending public-login 2FA method.
     *
     * @param array<string, mixed> $post
     */
    public function loginTwoFactorSelect(array $post): void
    {
        $requestedRedirect = $this->publicPostLoginRedirectFromValue((string) ($post['redirect_to'] ?? ''));
        $this->storePublicPostLoginRedirect($requestedRedirect);

        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flashPublic('error', 'Invalid CSRF token.');
            \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
        }

        $result = $this->loginChallengeWorkflowService()->selectMethod($this->auth, $this->loginUiState(), $post);
        if (($result['status'] ?? '') === 'expired') {
            $this->auth->logout();
            $this->clearPublicPostLoginRedirect();
            $this->flashPublic('error', (string) ($result['message'] ?? 'Your login session expired. Please log in again.'));
            \Raven\Core\Support\redirect($this->loginPathWithRedirect($requestedRedirect));
        }

        if (($result['status'] ?? '') === 'invalid_method') {
            $this->flashPublic('error', (string) ($result['message'] ?? 'Selected verification method is invalid.'));
        }

        \Raven\Core\Support\redirect($this->loginTwoFactorPathWithRedirect($requestedRedirect));
    }

    /**
     * Returns WebAuthn assertion options for pending public-login 2FA challenge.
     *
     * @param array<string, mixed> $post
     */
    public function loginTwoFactorWebauthnOptions(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $result = $this->loginChallengeWorkflowService()->webauthnOptions($this->auth, $this->loginUiState(), $_SERVER);
        if (!(bool) ($result['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($result['message'] ?? 'Failed to initialize WebAuthn challenge.')],
                (int) ($result['http_status'] ?? 400)
            );
            return;
        }

        $this->jsonResponse(
            is_array($result['payload'] ?? null) ? $result['payload'] : ['ok' => true],
            (int) ($result['http_status'] ?? 200)
        );
    }

    /**
     * Verifies WebAuthn assertion for pending public-login challenge.
     *
     * @param array<string, mixed> $post
     */
    public function loginTwoFactorWebauthnVerify(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->jsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
            return;
        }

        $result = $this->loginChallengeWorkflowService()->verifyWebauthn(
            $this->auth,
            $this->loginUiState(),
            $post,
            $_SERVER
        );
        if (!(bool) ($result['ok'] ?? false)) {
            $this->jsonResponse(
                ['ok' => false, 'message' => (string) ($result['message'] ?? 'Security key verification failed.')],
                (int) ($result['http_status'] ?? 400)
            );
            return;
        }

        $this->jsonResponse(['ok' => true, 'redirect' => $this->consumePublicPostLoginRedirectOrDefault()], 200);
    }

    /**
     * Renders public registration page.
     */
    public function register(): void
    {
        $registrationMode = $this->routeConfigService()->registrationMode();
        $loginIdentifierMode = $this->identifierResolver->modeFromConfig($this->config);
        $this->renderPublic('auth/register', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'captchaMarkup' => $this->publicCaptchaMarkup(),
            'flashSuccess' => $this->pullPublicFlash('success'),
            'flashError' => $this->pullPublicFlash('error'),
            'registrationMode' => $registrationMode,
            'registrationClosed' => $registrationMode === 'closed',
            'registrationInvite' => $registrationMode === 'invite',
            'loginIdentifierMode' => $loginIdentifierMode,
            'usernameRequired' => $loginIdentifierMode === 'username',
            'loginPath' => '/login',
        ], 'wrapper');
    }

    /**
     * Handles public registration submission.
     *
     * @param array<string, mixed> $post
     */
    public function registerSubmit(array $post): void
    {
        if (!$this->csrf->validate($post['_csrf'] ?? null)) {
            $this->flashPublic('error', 'Invalid CSRF token.');
            \Raven\Core\Support\redirect('/register');
        }

        $registrationMode = $this->routeConfigService()->registrationMode();
        if ($registrationMode === 'closed') {
            $this->flashPublic('error', 'Registration is currently closed.');
            \Raven\Core\Support\redirect('/register');
        }

        if ($this->isRegistrationTemporarilyLocked()) {
            $this->flashPublic('error', 'Too many registration attempts. Please wait a few minutes and try again.');
            \Raven\Core\Support\redirect('/register');
        }

        $loginIdentifierMode = $this->identifierResolver->modeFromConfig($this->config);
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $normalizedUsername = $this->identifierResolver->normalizeUsernameOrEmail($this->input, $rawUsername);
        $displayName = $this->input->text($post['display_name'] ?? null, 160);
        $email = $this->input->email($post['email'] ?? null);
        $password = $this->input->text($post['password'] ?? null, 255);
        $passwordConfirm = $this->input->text($post['password_confirm'] ?? null, 255);
        $inviteToken = $this->input->text($post['invite_token'] ?? null, 255);

        $errors = [];
        $usernameRequired = $loginIdentifierMode === 'username';
        if ($usernameRequired && !is_string($normalizedUsername)) {
            $errors[] = 'Username is required and must be valid.';
        }
        if (!$usernameRequired && $rawUsername !== '' && !is_string($normalizedUsername)) {
            $errors[] = 'Username must be valid when provided.';
        }
        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }
        if ($password === '' || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!hash_equals($password, $passwordConfirm)) {
            $errors[] = 'Password confirmation does not match.';
        }
        $captchaError = $this->validatePublicCaptcha();
        if ($captchaError !== null) {
            $errors[] = $captchaError;
        }

        $usableInvite = null;
        $now = time();
        if ($registrationMode === 'invite') {
            if ($inviteToken === '') {
                $errors[] = 'Invite token is required in invite-only mode.';
            } else {
                $usableInvite = $this->inviteTokens()->findUsableByToken($inviteToken, $now);
                if ($usableInvite === null) {
                    $errors[] = 'Invite token is invalid, expired, or already used.';
                }
            }
        }

        $groupIds = $this->registrationGroupIds();
        if ($groupIds === []) {
            $errors[] = 'Registration target group is unavailable. Contact an administrator.';
        }

        if ($errors !== []) {
            $this->recordRegistrationFailure();
            $this->flashPublic('error', implode(' ', $errors));
            \Raven\Core\Support\redirect('/register');
        }

        $savedUserId = null;
        try {
            $savedUserId = $this->userRepo->save([
                'id' => null,
                'username' => is_string($normalizedUsername) ? $normalizedUsername : '',
                'display_name' => $displayName !== '' ? $displayName : (string) $email,
                'email' => (string) $email,
                'theme' => 'default',
                'password' => $password,
                'group_ids' => $groupIds,
                'contact_profiles' => [],
                'set_avatar' => false,
                'avatar_path' => null,
                'string_length' => (int) $this->config->get('user.string', 28),
            ]);

            if (is_array($usableInvite)) {
                $inviteId = (int) ($usableInvite['id'] ?? 0);
                $isReusable = (int) ($usableInvite['reusable'] ?? 0) === 1;
                if ($inviteId < 1 || !$this->inviteTokens()->consume($inviteId, $isReusable, $now)) {
                    if (is_int($savedUserId) && $savedUserId > 0) {
                        try {
                            $this->userRepo->deleteById($savedUserId);
                        } catch (\Throwable) {
                            // Keep original consume failure message.
                        }
                    }

                    $this->recordRegistrationFailure();
                    $this->flashPublic('error', 'Invite token is no longer available. Please request a new token.');
                    \Raven\Core\Support\redirect('/register');
                }
            }
        } catch (\Throwable $exception) {
            $this->recordRegistrationFailure();
            error_log(
                'Raven public registration failed: '
                . $exception::class
                . ' - '
                . $exception->getMessage()
            );
            $this->flashPublic('error', 'Unable to create account with the provided details. Please review your submission and try again.');
            \Raven\Core\Support\redirect('/register');
        }

        $this->clearRegistrationFailures();
        $this->flashPublic('success', 'Account created. You can sign in if your account has dashboard access.');
        \Raven\Core\Support\redirect('/login');
    }

    /**
     * Renders profile-disabled/private-denied placeholder with explicit status.
     */
    private function renderProfileUnavailable(string $error, string $mode): void
    {
        $payload = $this->publicRouteRenderService()->profileUnavailablePayload($error, $mode, $this->siteData());
        http_response_code((int) ($payload['status'] ?? 404));
        $this->renderPublic(
            (string) ($payload['template'] ?? 'profile/index'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
    }

    /**
     * Renders group-route disabled/private-denied placeholder with explicit status.
     */
    private function renderGroupUnavailable(string $error, string $mode): void
    {
        $payload = $this->publicRouteRenderService()->groupUnavailablePayload($error, $mode, $this->siteData());
        http_response_code((int) ($payload['status'] ?? 404));
        $this->renderPublic(
            (string) ($payload['template'] ?? 'group/index'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
    }

    /**
     * Attaches label/href metadata to public profile contact rows.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function decoratePublicProfileContacts(array $profile): array
    {
        $profileContactOptions = $this->profileContactService()->normalizeOptionsConfig(
            $this->config->get('user.contact', $this->profileContactService()->defaultOptions())
        );

        return $this->profileContactService()->decorateProfileContacts($profile, $profileContactOptions);
    }

    /**
     * Handles one public embedded-form submission request by type + slug.
     */
    public function submitEmbeddedForm(string $type, string $formSlug): void
    {
        $runtime = $this->embeddedFormRuntimeService()->runtime($type, $this->embeddedFormRuntimes());
        if ($runtime === null) {
            $this->notFound();
            return;
        }

        if (!$this->embeddedFormRuntimeService()->isRuntimeEnabled($runtime)) {
            $this->notFound();
            return;
        }

        // Content-only runtimes (EmbeddedShortcodeRuntimeInterface) have no submit handler.
        if (!$runtime instanceof EmbeddedFormRuntimeInterface) {
            $this->notFound();
            return;
        }

        $slug = $this->input->slug($formSlug);
        if ($slug === null) {
            $this->notFound();
            return;
        }

        $returnPath = $this->embeddedFormRuntimeService()->sanitizeReturnPath((string) ($_POST['return_path'] ?? '/'));

        try {
            $runtime->submit($slug, $returnPath, function (): ?string {
                return $this->validatePublicCaptcha();
            });
        } catch (\Throwable $exception) {
            error_log(
                'Raven embedded form submit failed for type "'
                . $runtime->type()
                . '": '
                . $exception->getMessage()
            );
            $this->notFound();
        }
    }

    /**
     * Enforces global frontend availability mode before route handling.
     */
    public function enforceSiteAvailability(): bool
    {
        $mode = strtolower(trim((string) $this->config->get('site.visibility', 'public')));
        if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
            $mode = 'public';
        }
        $isLoggedIn = $this->auth->isLoggedIn();
        $payload = $this->publicRouteRenderService()->availabilityGatePayload(
            $mode,
            $isLoggedIn,
            $isLoggedIn && $this->auth->canViewDisabledSite(),
            $isLoggedIn && $this->auth->canViewPrivateSite(),
            $this->auth->canViewPublicSite(),
            $this->siteData()
        );
        if ((bool) ($payload['allowed'] ?? false)) {
            return true;
        }

        http_response_code((int) ($payload['status'] ?? 403));
        $this->renderPublic(
            (string) ($payload['template'] ?? 'status/denied'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
        return false;
    }

    /**
     * Renders public not-found page.
     */
    public function notFound(): void
    {
        $payload = $this->publicRouteRenderService()->notFoundPayload($this->siteData());
        http_response_code((int) ($payload['status'] ?? 404));
        $this->renderPublic(
            (string) ($payload['template'] ?? 'status/404'),
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            (string) ($payload['layout'] ?? 'wrapper')
        );
    }

    /**
     * Collects site config values required by public templates.
     *
     * @return array<string, mixed>
     */
    private function siteData(): array
    {
        return $this->publicMetaService()->siteData($this->config);
    }

    /**
     * Returns site data with page-level social metadata overrides when available.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function siteDataWithPageMeta(array $page): array
    {
        $profileContactOptions = $this->profileContactService()->normalizeOptionsConfig(
            $this->config->get('user.contact', $this->profileContactService()->defaultOptions())
        );

        return $this->publicMetaService()->siteDataWithPageMeta(
            $page,
            $this->siteData(),
            fn (int $pageId): ?string => $this->pageImages->coverImageUrlForPage($pageId),
            fn (int $authorUserId): ?array => $this->userRepo->findById($authorUserId),
            $profileContactOptions
        );
    }

    /**
     * Returns site data with taxonomy-level OG/Twitter image override when available.
     *
     * @param array<string, mixed> $taxonomy
     * @param array<string, mixed>|null $baseSiteData
     * @return array<string, mixed>
     */
    private function siteDataWithTaxonomyMetaImage(array $taxonomy, ?array $baseSiteData = null): array
    {
        return $this->publicMetaService()->siteDataWithTaxonomyMetaImage(
            $taxonomy,
            $baseSiteData ?? $this->siteData()
        );
    }

    /**
     * Resolves active public theme slug from configuration + discovered manifests.
     */
    private function currentPublicThemeSlug(): string
    {
        return $this->themeCatalogService()->activeSlugFromConfig($this->config);
    }

    /**
     * Returns filesystem root containing public themes.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService()->root();
    }

    /**
     * Normalizes and shortcode-renders repeatable page content blocks.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function renderPageContentBlocks(array $page): array
    {
        $rawBlocks = $page['content_blocks'] ?? null;
        if (!is_array($rawBlocks)) {
            $rawBlocks = [];
        }

        $renderedBlocks = [];
        $galleryBlockHtml = null;
        foreach ($rawBlocks as $block) {
            $type = 'tinymce';
            $content = '';
            $cssId = '';
            $cssClass = '';

            if (is_array($block)) {
                $type = $this->normalizePageBodyBlockType((string) ($block['type'] ?? 'tinymce'));
                $value = $block['content'] ?? '';
                $cssId = $this->normalizeBodyBlockCssId($block['css_id'] ?? null);
                $cssClass = $this->normalizeBodyBlockCssClassList($block['css_class'] ?? null);
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }
                $content = (string) ($value ?? '');
            } else {
                if (!is_scalar($block) && $block !== null) {
                    continue;
                }
                $content = (string) ($block ?? '');
            }

            if ($this->pageBodyBlockEditorMode($type) === 'gallery') {
                if (!is_string($galleryBlockHtml)) {
                    $galleryBlockHtml = $this->renderPageGalleryBlockHtml($page);
                }
                if (trim($galleryBlockHtml) === '') {
                    continue;
                }
                $renderedBlocks[] = [
                    'html' => $galleryBlockHtml,
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

            $html = $this->renderPageBodyBlockByType($type, $content);
            if (trim($html) === '') {
                continue;
            }

            $renderedBlocks[] = [
                'html' => $html,
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
        }

        $page['content_blocks'] = $renderedBlocks;
        return $page;
    }

    /**
     * Renders one gallery body block from page image rows.
     *
     * @param array<string, mixed> $page
     */
    private function renderPageGalleryBlockHtml(array $page): string
    {
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId <= 0) {
            return '';
        }

        $galleryImages = $this->decorateGalleryImagesForTemplate(
            $this->pageImages->listReadyForPublicPage($pageId)
        );
        if ($galleryImages === []) {
            return '';
        }

        $items = [];
        foreach ($galleryImages as $image) {
            if (!is_array($image)) {
                continue;
            }

            $imageUrl = trim((string) ($image['image_url'] ?? ''));
            $fullUrl = trim((string) ($image['full_url'] ?? ''));
            if ($imageUrl === '' && $fullUrl === '') {
                continue;
            }

            if ($imageUrl === '') {
                $imageUrl = $fullUrl;
            }
            if ($fullUrl === '') {
                $fullUrl = $imageUrl;
            }

            $altText = trim((string) ($image['alt_text'] ?? ''));
            $caption = trim((string) ($image['caption'] ?? ''));
            $captionHtml = $caption !== ''
                ? '<figcaption class="small text-muted mt-2">' . $this->escapeHtml($caption) . '</figcaption>'
                : '';

            $items[] = '<div class="col-12 col-md-6 col-lg-4"><figure class="mb-0">'
                . '<a href="' . $this->escapeHtml($fullUrl) . '">'
                . '<img src="' . $this->escapeHtml($imageUrl) . '" class="img-fluid rounded border" alt="' . $this->escapeHtml($altText) . '">'
                . '</a>'
                . $captionHtml
                . '</figure></div>';
        }

        if ($items === []) {
            return '';
        }

        return '<section class="mt-4"><div class="row g-3">' . implode('', $items) . '</div></section>';
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Renders one body block into public HTML.
     */
    private function renderPageBodyBlockByType(string $type, string $content): string
    {
        $editorMode = $this->pageBodyBlockEditorMode($this->normalizePageBodyBlockType($type));
        return $this->pageBodyRenderer()->renderByEditorMode(
            $editorMode,
            $content,
            fn (string $html): string => $this->renderEmbeddedForms($html)
        );
    }

    /**
     * Normalizes one optional body-block CSS id token.
     */
    private function normalizeBodyBlockCssId(mixed $value): string
    {
        return $this->bodyBlockPolicy()->normalizeCssId($value);
    }

    /**
     * Normalizes optional body-block CSS class list into one space-delimited value.
     */
    private function normalizeBodyBlockCssClassList(mixed $value): string
    {
        return $this->bodyBlockPolicy()->normalizeCssClassList($value);
    }

    /**
     * Normalizes one page body-block type value.
     */
    private function normalizePageBodyBlockType(string $value): string
    {
        return $this->bodyBlockPolicy()->normalizeType($value, $this->pageBodyBlockTypeDefinitions());
    }

    /**
     * Resolves editor mode for one public page body-block type key.
     */
    private function pageBodyBlockEditorMode(string $type): string
    {
        return $this->bodyBlockPolicy()->editorMode($type, $this->pageBodyBlockTypeDefinitions());
    }

    /**
     * Returns public page body-block type definitions.
     *
     * @return array<string, array{label: string, editor: string}>
     */
    private function pageBodyBlockTypeDefinitions(): array
    {
        if (is_array($this->pageBodyBlockTypeDefinitionsCache)) {
            return $this->pageBodyBlockTypeDefinitionsCache;
        }

        $definitions = $this->bodyBlockPolicy()->defaultDefinitions();
        foreach ($this->extensionEditorCatalogService()->publicBodyBlockDefinitions() as $type => $definition) {
            if (isset($definitions[$type])) {
                continue;
            }

            $definitions[$type] = $definition;
        }

        $this->pageBodyBlockTypeDefinitionsCache = $definitions;
        return $definitions;
    }

    /**
     * Returns registration default group ids.
     *
     * @return array<int>
     */
    private function registrationGroupIds(): array
    {
        foreach (['user', 'guest', 'validating'] as $slug) {
            $groupId = $this->groupRepo->idBySlug($slug);
            if (is_int($groupId) && $groupId > 0) {
                return [$groupId];
            }
        }

        return [];
    }

    /**
     * Stores one public flash message.
     */
    private function flashPublic(string $key, string $value): void
    {
        $this->publicFlash->put($key, $value);
    }

    /**
     * Pulls and clears one public flash message.
     */
    private function pullPublicFlash(string $key): ?string
    {
        return $this->publicFlash->pull($key);
    }

    private function consumePublicPostLoginRedirectOrDefault(): string
    {
        $raw = $this->loginUiState()->consumePostLoginRedirect();
        $normalized = $this->publicPostLoginRedirectFromValue($raw);
        return $normalized !== '' ? $normalized : '/';
    }

    private function clearPublicPostLoginRedirect(): void
    {
        $this->loginUiState()->clearAll();
    }

    private function storePublicPostLoginRedirect(string $value): void
    {
        $normalized = $this->publicPostLoginRedirectFromValue($value);
        $this->loginUiState()->storePostLoginRedirect($normalized !== '' ? $normalized : '/');
    }

    private function publicPostLoginRedirectFromRequest(): string
    {
        $queryValue = $this->publicPostLoginRedirectFromValue((string) ($_GET['redirect_to'] ?? ''));
        if ($queryValue !== '') {
            return $queryValue;
        }

        $storedValue = $this->publicPostLoginRedirectFromValue($this->loginUiState()->postLoginRedirect());
        if ($storedValue !== '') {
            return $storedValue;
        }

        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '' && RedirectTargetValidator::isAllowedHttpOrRootPath($referer)) {
            $parts = parse_url($referer);
            if (is_array($parts)) {
                $host = strtolower(trim((string) ($parts['host'] ?? '')));
                $currentHost = strtolower($this->requestContextResolver()->resolveRequestHost((string) $this->config->get('site.domain', 'localhost')));
                if ($host !== '' && $host === $currentHost) {
                    $candidate = (string) ($parts['path'] ?? '/');
                    if (isset($parts['query']) && $parts['query'] !== '') {
                        $candidate .= '?' . (string) $parts['query'];
                    }
                    $normalized = $this->publicPostLoginRedirectFromValue($candidate);
                    if ($normalized !== '' && !$this->isPublicAuthPath($normalized)) {
                        return $normalized;
                    }
                }
            }
        }

        return '/';
    }

    private function publicPostLoginRedirectFromValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return '';
        }

        $parts = @parse_url($value);
        if (!is_array($parts)) {
            return '';
        }

        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || !str_starts_with($path, '/')) {
            return '';
        }

        if (str_contains($path, "\0")) {
            return '';
        }

        $panelBase = trim($this->panelUrl(''));
        if ($panelBase !== '' && str_starts_with($path, $panelBase)) {
            return '';
        }

        if ($this->isPublicAuthPath($path)) {
            return '';
        }

        $normalized = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . (string) $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $normalized .= '#' . (string) $parts['fragment'];
        }

        return $normalized;
    }

    private function loginPathWithRedirect(string $redirectPath): string
    {
        $normalized = $this->publicPostLoginRedirectFromValue($redirectPath);
        if ($normalized === '' || $normalized === '/') {
            return '/login';
        }

        return '/login?redirect_to=' . rawurlencode($normalized);
    }

    private function loginTwoFactorPathWithRedirect(string $redirectPath): string
    {
        $normalized = $this->publicPostLoginRedirectFromValue($redirectPath);
        if ($normalized === '' || $normalized === '/') {
            return '/login/2fa';
        }

        return '/login/2fa?redirect_to=' . rawurlencode($normalized);
    }

    private function loginTwoFactorSelectPathWithRedirect(string $redirectPath): string
    {
        $normalized = $this->publicPostLoginRedirectFromValue($redirectPath);
        if ($normalized === '' || $normalized === '/') {
            return '/login/2fa/select';
        }

        return '/login/2fa/select?redirect_to=' . rawurlencode($normalized);
    }

    private function isPublicAuthPath(string $path): bool
    {
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        return in_array($path, [
            '/login',
            '/login/2fa',
            '/login/2fa/select',
            '/login/2fa/webauthn/options',
            '/login/2fa/webauthn/verify',
            '/register',
        ], true);
    }

    /**
     * Builds panel URL with configured panel-path prefix.
     */
    private function panelUrl(string $suffix = ''): string
    {
        return PanelUrl::fromConfig($this->config, $suffix);
    }

    private function siteContextBuilder(): SiteContextBuilder
    {
        if (!$this->siteContextBuilder instanceof SiteContextBuilder) {
            $this->siteContextBuilder = new SiteContextBuilder();
        }

        return $this->siteContextBuilder;
    }

    private function markdownRenderer(): MarkdownRenderer
    {
        if (!$this->markdownRenderer instanceof MarkdownRenderer) {
            $this->markdownRenderer = new MarkdownRenderer();
        }

        return $this->markdownRenderer;
    }

    private function pageBodyRenderer(): PublicPageBodyRenderer
    {
        if (!$this->pageBodyRenderer instanceof PublicPageBodyRenderer) {
            $this->pageBodyRenderer = new PublicPageBodyRenderer(
                dirname(__DIR__, 3),
                $this->markdownRenderer()
            );
        }

        return $this->pageBodyRenderer;
    }

    private function requestContextResolver(): RequestContextResolver
    {
        if (!$this->requestContextResolver instanceof RequestContextResolver) {
            $this->requestContextResolver = new RequestContextResolver();
        }

        return $this->requestContextResolver;
    }

    private function loginUiState(): LoginUiStateService
    {
        if (!$this->loginUiState instanceof LoginUiStateService) {
            $this->loginUiState = LoginUiStateService::forPublic();
        }

        return $this->loginUiState;
    }

    private function loginAttemptPolicy(): LoginAttemptPolicy
    {
        if (!$this->loginAttemptPolicy instanceof LoginAttemptPolicy) {
            $this->loginAttemptPolicy = new LoginAttemptPolicy($this->config, $this->requestContextResolver());
        }

        return $this->loginAttemptPolicy;
    }

    private function loginAttemptWorkflowService(): LoginAttemptWorkflowService
    {
        if (!$this->loginAttemptWorkflowService instanceof LoginAttemptWorkflowService) {
            $this->loginAttemptWorkflowService = new LoginAttemptWorkflowService(
                $this->config,
                $this->input,
                $this->identifierResolver,
                $this->loginAttemptPolicy(),
                new \Raven\Lib\Security\LoginTwoFactorFlowService()
            );
        }

        return $this->loginAttemptWorkflowService;
    }

    private function publicChannelPageRouteService(): PublicChannelPageRouteService
    {
        if (!$this->publicChannelPageRouteService instanceof PublicChannelPageRouteService) {
            $this->publicChannelPageRouteService = new PublicChannelPageRouteService($this->input);
        }

        return $this->publicChannelPageRouteService;
    }

    private function loginChallengeWorkflowService(): LoginChallengeWorkflowService
    {
        if (!$this->loginChallengeWorkflowService instanceof LoginChallengeWorkflowService) {
            $this->loginChallengeWorkflowService = new LoginChallengeWorkflowService(
                $this->config,
                $this->input,
                new \Raven\Lib\Security\LoginTwoFactorFlowService(),
                new \Raven\Lib\Auth\LoginWebAuthnChallengeService(),
                new \Raven\Lib\Auth\TwoFactorEmailDeliveryService()
            );
        }

        return $this->loginChallengeWorkflowService;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        HttpResponse::json($payload, $status, true);
    }

    private function publicTemplateResolver(): PublicTemplateResolver
    {
        if (!$this->publicTemplateResolver instanceof PublicTemplateResolver) {
            $this->publicTemplateResolver = new PublicTemplateResolver($this->input);
        }

        return $this->publicTemplateResolver;
    }

    private function publicTemplatePipeline(): PublicTemplatePipeline
    {
        if (!$this->publicTemplatePipeline instanceof PublicTemplatePipeline) {
            $this->publicTemplatePipeline = new PublicTemplatePipeline($this->publicTemplateResolver());
        }

        return $this->publicTemplatePipeline;
    }

    private function embeddedFormRuntimeService(): EmbeddedFormRuntimeService
    {
        if (!$this->embeddedFormRuntimeService instanceof EmbeddedFormRuntimeService) {
            $this->embeddedFormRuntimeService = new EmbeddedFormRuntimeService($this->input, dirname(__DIR__, 3));
        }

        return $this->embeddedFormRuntimeService;
    }

    /**
     * Returns the extension-services map, booting extensions only when form runtimes are needed.
     *
     * @return array<string, mixed>
     */
    private function extensionServices(): array
    {
        if (!$this->extensionServicesProvider instanceof Closure) {
            return [];
        }

        /** @var mixed $services */
        $services = ($this->extensionServicesProvider)();
        return is_array($services) ? $services : [];
    }

    /**
     * Returns the discovered embedded shortcode/form runtimes for the current public request.
     *
     * @return array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface>
     */
    private function embeddedFormRuntimes(): array
    {
        if (!$this->embeddedFormRuntimesLoaded) {
            $this->embeddedFormRuntimes = $this->embeddedFormRuntimeService()->discoverRuntimes($this->extensionServices());
            $this->embeddedFormRuntimesLoaded = true;
        }

        return $this->embeddedFormRuntimes;
    }

    private function profileContactService(): ProfileContactService
    {
        if (!$this->profileContactService instanceof ProfileContactService) {
            $this->profileContactService = new ProfileContactService($this->input);
        }

        return $this->profileContactService;
    }

    private function routeConfigService(): RouteConfigService
    {
        if (!$this->routeConfigService instanceof RouteConfigService) {
            $this->routeConfigService = new RouteConfigService($this->config, $this->input);
        }

        return $this->routeConfigService;
    }

    private function globalPageRouteMode(): string
    {
        return $this->routeConfigService()->globalPageRouteMode();
    }

    private function effectiveChannelRouteMode(string $channelValue): string
    {
        return $this->routeConfigService()->effectiveChannelRouteMode($channelValue);
    }

    private function bodyBlockPolicy(): BodyBlockPolicy
    {
        if (!$this->bodyBlockPolicy instanceof BodyBlockPolicy) {
            $this->bodyBlockPolicy = new BodyBlockPolicy($this->input);
        }

        return $this->bodyBlockPolicy;
    }

    private function captchaService(): CaptchaService
    {
        if (!$this->captchaService instanceof CaptchaService) {
            $this->captchaService = new CaptchaService($this->config, $this->input);
        }

        return $this->captchaService;
    }

    private function themeCatalogService(): ThemeCatalogService
    {
        if (!$this->themeCatalogService instanceof ThemeCatalogService) {
            $this->themeCatalogService = new ThemeCatalogService(
                dirname(__DIR__, 3) . '/public/theme',
                $this->input,
                ['raven']
            );
        }

        return $this->themeCatalogService;
    }

    private function extensionEditorCatalogService(): ExtensionEditorCatalogService
    {
        if (!$this->extensionEditorCatalogService instanceof ExtensionEditorCatalogService) {
            $this->extensionEditorCatalogService = new ExtensionEditorCatalogService(
                dirname(__DIR__, 3),
                $this->input,
                $this->bodyBlockPolicy()
            );
        }

        return $this->extensionEditorCatalogService;
    }

    private function publicMetaService(): PublicMetaService
    {
        if (!$this->publicMetaService instanceof PublicMetaService) {
            $this->publicMetaService = new PublicMetaService(
                $this->requestContextResolver(),
                $this->siteContextBuilder(),
                $this->themeCatalogService(),
                $this->profileContactService(),
                $this->routeConfigService()
            );
        }

        return $this->publicMetaService;
    }

    private function publicTemplateDecorator(): PublicTemplateDecorator
    {
        if (!$this->publicTemplateDecorator instanceof PublicTemplateDecorator) {
            $this->publicTemplateDecorator = new PublicTemplateDecorator($this->config, $this->input, dirname(__DIR__, 3));
        }

        return $this->publicTemplateDecorator;
    }

    private function publicRouteRenderService(): PublicRouteRenderService
    {
        if (!$this->publicRouteRenderService instanceof PublicRouteRenderService) {
            $this->publicRouteRenderService = new PublicRouteRenderService();
        }

        return $this->publicRouteRenderService;
    }

    /**
     * Expands pagination payload into template-ready link rows.
     *
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>
     */
    private function decoratePaginationForTemplate(array $pagination): array
    {
        return $this->publicTemplateDecorator()->decoratePaginationForTemplate($pagination);
    }

    /**
     * Adds template-friendly derived keys for page render views.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function decoratePageForTemplate(array $page): array
    {
        return $this->publicTemplateDecorator()->decoratePageForTemplate($page);
    }

    /**
     * Adds template-ready URL fields for gallery image rows.
     *
     * @param array<int, array<string, mixed>> $galleryImages
     * @return array<int, array<string, mixed>>
     */
    private function decorateGalleryImagesForTemplate(array $galleryImages): array
    {
        return $this->publicTemplateDecorator()->decorateGalleryImagesForTemplate($galleryImages);
    }

    /**
     * Adds template-friendly derived keys to one public profile payload.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function decorateProfileForTemplate(array $profile): array
    {
        return $this->publicTemplateDecorator()->decorateProfileForTemplate($profile);
    }

    /**
     * Adds template-friendly member rows for group list templates.
     *
     * @param array<int, array<string, mixed>> $members
     * @return array<int, array<string, mixed>>
     */
    private function decorateGroupMembersForTemplate(array $members): array
    {
        return $this->publicTemplateDecorator()->decorateGroupMembersForTemplate($members);
    }

    /**
     * Adds derived fields for group templates.
     *
     * @param array<string, mixed> $group
     * @param array<int, array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private function decorateGroupForTemplate(array $group, array $members): array
    {
        return $this->publicTemplateDecorator()->decorateGroupForTemplate($group, $members);
    }

    /**
     * Injects shared wrapper metadata derived from route payloads.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function decorateTemplateData(array $data): array
    {
        $statusCode = http_response_code();
        if (!is_int($statusCode)) {
            $statusCode = 200;
        }

        return $this->publicTemplateDecorator()->decorateTemplateData($data, $statusCode);
    }

    /**
     * Renders one public template with theme-aware lookup and private fallback.
     *
     * Theme lookup order:
     * 1) `public/theme/{active_theme}/tpl/{template}.php`
     * 2) `private/tpl/{template}.php`
     *
     * @param array<string, mixed> $data
     */
    private function renderPublic(string $template, array $data = [], ?string $layout = null): void
    {
        $data = $this->decorateTemplateData($data);
        $output = $this->publicTemplatePipeline()->renderForThemeChain(
            $template,
            $data,
            $layout,
            fn (string $file, array $payload): string => $this->templateTags->renderFile($file, $payload),
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 3) . '/private/tpl'
        );

        echo $output;
    }

    /**
     * Verifies configured public captcha response in current request.
     *
     * @return string|null One user-facing validation error, or null when captcha passes.
     */
    private function validatePublicCaptcha(): ?string
    {
        $remoteIp = $this->requestContextResolver()->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $this->captchaService()->validateSubmission($_POST, $remoteIp);
    }

    /**
     * Returns captcha widget + script markup for public embedded forms.
     */
    private function publicCaptchaMarkup(): string
    {
        $markup = $this->captchaService()->publicMarkup($this->captchaScriptIncluded);
        $this->captchaScriptIncluded = (bool) ($markup['script_included'] ?? $this->captchaScriptIncluded);
        return (string) ($markup['markup'] ?? '');
    }

    /**
     * Resolves one public profile using the configured public route selector strategy.
     *
     * @return array<string, mixed>|null
     */
    private function findPublicProfileByRouteSegment(string $routeSegment): ?array
    {
        $selector = $this->routeConfigService()->profileSelector();
        if ($selector === 'id') {
            $userId = $this->input->int($routeSegment, 1);
            if ($userId === null) {
                return null;
            }

            return $this->userRepo->findPublicProfileById($userId);
        }

        if ($selector === 'string') {
            $normalizedString = trim($routeSegment);
            if ($normalizedString === '' || preg_match('/^[a-zA-Z0-9]+$/', $normalizedString) !== 1) {
                return null;
            }

            return $this->userRepo->findPublicProfileByString($normalizedString);
        }

        $normalizedUsername = $this->identifierResolver->normalizeUsernameOrEmail($this->input, $routeSegment);
        if ($normalizedUsername === null) {
            return null;
        }

        return $this->userRepo->findPublicProfileByUsername($normalizedUsername);
    }

    private function registrationThrottleIdentifier(): string
    {
        return 'register-public';
    }

    private function isRegistrationTemporarilyLocked(): bool
    {
        $policy = $this->loginAttemptPolicy();
        return $this->auth->isLoginTemporarilyLocked(
            $this->registrationThrottleIdentifier(),
            $policy->clientIpAddress($_SERVER),
            $policy->windowSeconds()
        );
    }

    private function recordRegistrationFailure(): void
    {
        $policy = $this->loginAttemptPolicy();
        $this->auth->recordFailedLoginAttempt(
            $this->registrationThrottleIdentifier(),
            $policy->clientIpAddress($_SERVER),
            $policy->maxAttempts(),
            $policy->windowSeconds(),
            $policy->lockSeconds()
        );
    }

    private function clearRegistrationFailures(): void
    {
        $policy = $this->loginAttemptPolicy();
        $this->auth->clearFailedLoginAttempts(
            $this->registrationThrottleIdentifier(),
            $policy->clientIpAddress($_SERVER)
        );
    }

    /**
     * Renders an extension template through the site theme pipeline.
     *
     * Inserts the extension's own tpl/ directory into the template lookup chain
     * between active-theme overrides and core fallbacks, so the active theme can
     * override extension templates while the extension's own templates take
     * priority over any matching name in the core tpl/ fallback.
     *
     * This is the public entry point for the `renderPublicExtension` callable
     * injected into extension public route context by public/index.php.
     *
     * @param string               $template         Template name (e.g., `public_index`).
     * @param array<string, mixed> $data             Template variables merged into global context.
     * @param string|null          $layout           Layout template, or null for no wrapper.
     * @param string               $extensionTplRoot Absolute path to the extension's tpl/ directory.
     */
    public function renderPublicExtensionTemplate(
        string $template,
        array $data = [],
        ?string $layout = 'wrapper',
        string $extensionTplRoot = ''
    ): void {
        $data = $this->decorateTemplateData($data);
        $pipeline = $this->publicTemplatePipeline();
        $roots = $pipeline->lookupRoots(
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 3) . '/private/tpl'
        );

        // Insert the extension tpl root between theme roots and the core fallback so the
        // active theme can still override extension templates while extension templates
        // take priority over any matching name in the core tpl/ fallback.
        if ($extensionTplRoot !== '' && is_dir($extensionTplRoot)) {
            array_splice($roots, count($roots) - 1, 0, [$extensionTplRoot]);
        }

        $output = $pipeline->render(
            $template,
            $data,
            $layout,
            fn (string $file, array $payload): string => $this->templateTags->renderFile($file, $payload),
            ...$roots
        );

        echo $output;
    }

    /**
     * Resolves supported form shortcodes inside editor HTML content.
     */
    private function renderEmbeddedForms(string $html): string
    {
        return $this->embeddedFormRuntimeService()->renderShortcodesForPublicRoute(
            $html,
            $this->embeddedFormRuntimes(),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $this->csrf->field(),
            fn (): string => $this->publicCaptchaMarkup()
        );
    }

}
