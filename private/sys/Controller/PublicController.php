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

use Raven\Core\Auth\AuthService;
use Raven\Core\Config;
use Raven\Core\Extension\EmbeddedFormRuntimeInterface;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Lib\Auth\LoginIdentifierResolver;
use Raven\Lib\Content\BodyBlockPolicy;
use Raven\Lib\Content\MarkdownRenderer;
use Raven\Lib\Extension\EmbeddedFormRuntimeService;
use Raven\Lib\Http\RequestContextResolver;
use Raven\Lib\Http\SessionFlash;
use Raven\Lib\Pagination\Pagination;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\ChannelRoutePolicy;
use Raven\Lib\Routing\PanelUrl;
use Raven\Lib\Routing\RedirectTargetValidator;
use Raven\Lib\Routing\RouteConfigService;
use Raven\Lib\Security\CaptchaService;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\PublicTemplateResolver;
use Raven\Core\Theme\PublicThemeRegistry;
use Raven\Core\View;
use Raven\Core\View\TemplateTagEngine;
use Raven\Repository\GroupRepository;
use Raven\Repository\InviteTokenRepository;
use Raven\Repository\PageImageRepository;
use Raven\Repository\PageRepository;
use Raven\Repository\RedirectRepository;
use Raven\Repository\TaxonomyRepository;
use Raven\Repository\UserRepository;

/**
 * Handles public website routes.
 */
final class PublicController
{
    private View $view;
    private Config $config;
    private AuthService $auth;
    private GroupRepository $groups;
    private PageImageRepository $pageImages;
    private PageRepository $pages;
    private RedirectRepository $redirects;
    private TaxonomyRepository $taxonomy;
    private UserRepository $users;
    private InviteTokenRepository $inviteTokens;
    private InputSanitizer $input;
    private Csrf $csrf;
    private SessionFlash $publicFlash;
    private LoginIdentifierResolver $identifierResolver;
    /** @var array<string, EmbeddedFormRuntimeInterface> */
    private array $embeddedFormRuntimes = [];
    private TemplateTagEngine $templateTags;
    private bool $captchaScriptIncluded = false;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;
    private ?SiteContextBuilder $siteContextBuilder = null;
    private ?MarkdownRenderer $markdownRenderer = null;
    private ?RequestContextResolver $requestContextResolver = null;
    private ?PublicTemplateResolver $publicTemplateResolver = null;
    private ?EmbeddedFormRuntimeService $embeddedFormRuntimeService = null;
    private ?ProfileContactService $profileContactService = null;
    private ?RouteConfigService $routeConfigService = null;
    private ?BodyBlockPolicy $bodyBlockPolicy = null;
    private ?CaptchaService $captchaService = null;
    public function __construct(
        View $view,
        Config $config,
        AuthService $auth,
        GroupRepository $groups,
        PageImageRepository $pageImages,
        PageRepository $pages,
        RedirectRepository $redirects,
        TaxonomyRepository $taxonomy,
        UserRepository $users,
        InviteTokenRepository $inviteTokens,
        InputSanitizer $input,
        Csrf $csrf,
        array $extensionServices = []
    )
    {
        $this->view = $view;
        $this->config = $config;
        $this->auth = $auth;
        $this->groups = $groups;
        $this->pageImages = $pageImages;
        $this->pages = $pages;
        $this->redirects = $redirects;
        $this->taxonomy = $taxonomy;
        $this->users = $users;
        $this->inviteTokens = $inviteTokens;
        $this->input = $input;
        $this->csrf = $csrf;
        $this->publicFlash = new SessionFlash('_raven_public_flash');
        $this->identifierResolver = new LoginIdentifierResolver();
        $this->embeddedFormRuntimes = $this->discoverEmbeddedFormRuntimes($extensionServices);
        $this->templateTags = new TemplateTagEngine(dirname(__DIR__, 3) . '/private/tmp/template_tag_cache');
    }

    /**
     * Discovers extension-provided embedded-form runtimes.
     *
     * @param array<string, mixed> $extensionServices
     * @return array<string, EmbeddedFormRuntimeInterface>
     */
    private function discoverEmbeddedFormRuntimes(array $extensionServices): array
    {
        $runtimes = [];

        foreach ($extensionServices as $serviceBucket) {
            if (!is_array($serviceBucket)) {
                continue;
            }

            /** @var mixed $rawCandidates */
            $rawCandidates = $serviceBucket['embedded_form_runtimes'] ?? [];
            if (is_object($rawCandidates)) {
                $rawCandidates = [$rawCandidates];
            }
            if (!is_array($rawCandidates)) {
                continue;
            }

            foreach ($rawCandidates as $candidate) {
                if (!$candidate instanceof EmbeddedFormRuntimeInterface) {
                    continue;
                }

                $type = strtolower(trim($candidate->type()));
                if ($type === '' || $this->input->slug($type) === null) {
                    continue;
                }

                // First writer wins so one type cannot be overridden unexpectedly.
                if (!isset($runtimes[$type])) {
                    $runtimes[$type] = $candidate;
                }
            }
        }

        ksort($runtimes);
        return $runtimes;
    }

    /**
     * Renders homepage using `home` slug or `index` fallback, outside channels.
     */
    public function home(): void
    {
        $page = $this->pages->findHomepage();

        if ($page === null) {
            $this->notFound();
            return;
        }

        $galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1 || $this->pageBodyIncludesGalleryBlock($page);
        $galleryImages = $galleryEnabled
            ? $this->pageImages->listReadyForPublicPage((int) $page['id'])
            : [];

        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $page = $this->renderPageExtendedBlocks($page);
        $page = $this->decoratePageForTemplate($page);
        $galleryImages = $this->decorateGalleryImagesForTemplate($galleryImages);

        $this->renderPublic('home', [
            'site' => $this->siteDataWithPageMeta($page),
            'page' => $page,
            'galleryEnabled' => $galleryEnabled,
            'galleryImages' => $galleryImages,
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
        $page = $this->pages->findChannelHomepage($channelSlug);

        if ($page === null) {
            $this->page($channelSlug, null);
            return;
        }

        $channel = $this->taxonomy->findChannelBySlug($channelSlug);

        $galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1 || $this->pageBodyIncludesGalleryBlock($page);
        $galleryImages = $galleryEnabled
            ? $this->pageImages->listReadyForPublicPage((int) $page['id'])
            : [];

        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $page = $this->renderPageExtendedBlocks($page);
        $page = $this->decoratePageForTemplate($page);
        $galleryImages = $this->decorateGalleryImagesForTemplate($galleryImages);

        $channelTemplate = $this->resolveChannelTemplateName($channelSlug);
        $site = $this->siteDataWithPageMeta($page);
        if (is_array($channel)) {
            // Channel-level cover/preview uploads override default/page fallback for channel landing routes.
            $site = $this->siteDataWithTaxonomyMetaImage($channel, $site);
        }

        $this->renderPublic($channelTemplate, [
            'site' => $site,
            'page' => $page,
            'galleryEnabled' => $galleryEnabled,
            'galleryImages' => $galleryImages,
        ], 'wrapper');
    }

    /**
     * Renders one public page, optionally nested by channel slug.
     */
    public function page(string $pageSlug, ?string $channelSlug = null): void
    {
        $requestedSlug = strtolower(trim($pageSlug));
        $lookupSlug = $requestedSlug;
        $channelRouteMode = 'slug';
        $channelWordSeparator = '-';

        if ($channelSlug !== null) {
            $channel = $this->taxonomy->findChannelBySlug($channelSlug);
            if ($channel === null) {
                if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                    return;
                }

                $this->notFound();
                return;
            }

            $channelRouteMode = $this->normalizeChannelPageRouteMode(
                (string) ($channel['page_route_mode'] ?? 'slug')
            );
            $channelWordSeparator = $this->resolveChannelPageUrlSeparator(
                (string) ($channel['page_url_separator'] ?? 'inherit')
            );

            if ($channelRouteMode === 'date_slug') {
                $parsed = $this->parseChannelDateSlugSegment($requestedSlug, $channelWordSeparator);
                if ($parsed === null) {
                    if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                        return;
                    }

                    $this->notFound();
                    return;
                }

                $lookupSlug = (string) ($parsed['slug'] ?? $requestedSlug);
            } else {
                $normalizedLookupSlug = $this->normalizeChannelPageSlugForLookup($requestedSlug, $channelWordSeparator);
                if ($normalizedLookupSlug === null) {
                    if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                        return;
                    }

                    $this->notFound();
                    return;
                }

                $lookupSlug = $normalizedLookupSlug;
            }
        }

        $page = $this->pages->findPublicPage($lookupSlug, $channelSlug);

        if ($page === null) {
            // If no page exists at this path, attempt redirect fallback before 404.
            if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                return;
            }

            $this->notFound();
            return;
        }

        if ($channelSlug !== null) {
            $canonicalSegment = $this->channelPageRouteSegment(
                (string) ($page['slug'] ?? ''),
                (string) ($page['published_at'] ?? ''),
                $channelRouteMode,
                $channelWordSeparator
            );
            if ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
                \Raven\Core\Support\redirect(
                    '/' . rawurlencode($channelSlug) . '/' . rawurlencode($canonicalSegment),
                    301
                );
            }
        }

        $galleryEnabled = (int) ($page['gallery_enabled'] ?? 0) === 1 || $this->pageBodyIncludesGalleryBlock($page);
        $galleryImages = $galleryEnabled
            ? $this->pageImages->listReadyForPublicPage((int) $page['id'])
            : [];

        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $page = $this->renderPageExtendedBlocks($page);
        $page = $this->decoratePageForTemplate($page);
        $galleryImages = $this->decorateGalleryImagesForTemplate($galleryImages);

        $pageTemplate = $this->resolvePageTemplateName($channelSlug);

        $this->renderPublic($pageTemplate, [
            'site' => $this->siteDataWithPageMeta($page),
            'page' => $page,
            'galleryEnabled' => $galleryEnabled,
            'galleryImages' => $galleryImages,
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
            if ($slug === null || $slug === '') {
                $pages[$index]['public_path'] = '/';
                continue;
            }

            $channelSlug = $this->input->slug((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === null || $channelSlug === '') {
                $pages[$index]['public_path'] = '/' . rawurlencode($slug);
                continue;
            }

            $pages[$index]['public_path'] = '/'
                . rawurlencode($channelSlug)
                . '/'
                . rawurlencode(
                    $this->channelPageRouteSegment(
                        $slug,
                        (string) ($page['published_at'] ?? ''),
                        (string) ($page['channel_page_route_mode'] ?? 'slug'),
                        (string) ($page['channel_page_url_separator'] ?? 'inherit')
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
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $path = trim((string) ($page['public_path'] ?? ''));
            if ($path === '') {
                $slug = $this->input->slug((string) ($page['slug'] ?? ''));
                $channelSlug = $this->input->slug((string) ($page['channel_slug'] ?? ''));
                if ($slug === null || $slug === '') {
                    $path = '/';
                } elseif ($channelSlug === null || $channelSlug === '') {
                    $path = '/' . rawurlencode($slug);
                } else {
                    $path = '/' . rawurlencode($channelSlug) . '/' . rawurlencode($slug);
                }
            }

            $pages[$index]['public_path'] = $path;
        }

        return $pages;
    }

    /**
     * Normalizes one stored channel page-route mode value.
     */
    private function normalizeChannelPageRouteMode(string $value): string
    {
        return ChannelRoutePolicy::normalizeRouteMode($value);
    }

    /**
     * Resolves effective channel page-url separator from channel + global config.
     */
    private function resolveChannelPageUrlSeparator(string $channelValue): string
    {
        return $this->routeConfigService()->resolveChannelPageUrlSeparator($channelValue);
    }

    /**
     * Normalizes one channel route segment into canonical stored slug format.
     */
    private function normalizeChannelPageSlugForLookup(string $segment, string $wordSeparator): ?string
    {
        return ChannelRoutePolicy::normalizeSlugForLookup($this->input, $segment, $wordSeparator);
    }

    /**
     * Parses one `YYYY-MM-DD-{slug}` channel route segment.
     *
     * @return array{date: string, slug: string}|null
     */
    private function parseChannelDateSlugSegment(string $segment, string $wordSeparator): ?array
    {
        return ChannelRoutePolicy::parseDateSlugSegment($this->input, $segment, $wordSeparator);
    }

    /**
     * Returns one channel page route segment from slug + mode.
     */
    private function channelPageRouteSegment(
        string $slug,
        string $publishedAt,
        string $routeMode,
        string $wordSeparator
    ): string
    {
        return ChannelRoutePolicy::buildRouteSegment(
            $this->input,
            $slug,
            $publishedAt,
            $routeMode,
            $wordSeparator,
            (string) $this->config->get('content.separator', '-')
        );
    }

    /**
     * Attempts active redirect lookup for a URL path and emits HTTP redirect when found.
     */
    private function tryRedirect(string $pageSlug, ?string $channelSlug = null): bool
    {
        $redirect = $this->redirects->findActiveByPath($pageSlug, $channelSlug);
        if ($redirect === null) {
            return false;
        }

        $targetUrl = trim((string) ($redirect['target_url'] ?? ''));
        if (!$this->isAllowedRedirectTargetUrl($targetUrl)) {
            return false;
        }

        // Default behavior is temporary redirect; status configuration can be added later.
        \Raven\Core\Support\redirect($targetUrl, 302);
        return true;
    }

    /**
     * Safety check for redirect targets loaded from persistence.
     */
    private function isAllowedRedirectTargetUrl(string $targetUrl): bool
    {
        return RedirectTargetValidator::isAllowedHttpOrRootPath($targetUrl);
    }

    /**
     * Renders category listing route `/{category_prefix}/{category_slug}/{page?}`.
     */
    public function category(string $categorySlug, int $pageNumber = 1): void
    {
        $categoryPrefix = $this->categoryRoutePrefix();
        if ($categoryPrefix === '') {
            $this->notFound();
            return;
        }

        $category = $this->taxonomy->findCategoryBySlug($categorySlug);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $perPage = max(1, (int) $this->config->get('category.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pages->listPageByCategorySlug($categorySlug, $perPage, $offset);
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
        $categoryTemplate = $this->resolveCategoryTemplateName($categorySlug);

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
        $tagPrefix = $this->tagRoutePrefix();
        if ($tagPrefix === '') {
            $this->notFound();
            return;
        }

        $tag = $this->taxonomy->findTagBySlug($tagSlug);

        if ($tag === null) {
            $this->notFound();
            return;
        }

        $perPage = max(1, (int) $this->config->get('tag.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pages->listPageByTagSlug($tagSlug, $perPage, $offset);
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
        $tagTemplate = $this->resolveTagTemplateName($tagSlug);

        $this->renderPublic($tagTemplate, [
            'site' => $this->siteDataWithTaxonomyMetaImage($tag),
            'tag' => $tag,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Renders one public profile route `/{profile_prefix}/{username}`.
     */
    public function profile(string $username): void
    {
        $profileMode = $this->profileMode();
        $isLoggedIn = $this->auth->isLoggedIn();
        if ($this->profileRoutePrefix() === '') {
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

        $normalizedUsername = $this->normalizeProfileIdentifier($username);
        if ($normalizedUsername === null) {
            $this->notFound();
            return;
        }

        $profile = $this->users->findPublicProfileByUsername($normalizedUsername);
        if ($profile === null) {
            $this->notFound();
            return;
        }
        $profile = $this->decoratePublicProfileContacts($profile);
        $profile = $this->decorateProfileForTemplate($profile);

        $template = match ($profileMode) {
            'public_full' => 'profiles/full',
            'public_limited' => $isLoggedIn ? 'profiles/full' : 'profiles/limited',
            'private' => 'profiles/full',
            default => 'profiles/index',
        };

        $this->renderPublic($template, [
            'site' => $this->siteData(),
            'profile' => $profile,
        ], 'wrapper');
    }

    /**
     * Normalizes one profile-route identifier segment.
     *
     * Accepts canonical usernames and email-shaped values.
     */
    private function normalizeProfileIdentifier(string $rawIdentifier): ?string
    {
        $decoded = rawurldecode($rawIdentifier);
        $normalizedText = $this->input->text($decoded, 254);
        if ($normalizedText === '') {
            return null;
        }

        $normalizedUsername = $this->input->username($normalizedText);
        if ($normalizedUsername !== null && $normalizedUsername !== '') {
            return $normalizedUsername;
        }

        $normalizedEmail = $this->input->email($normalizedText);
        if ($normalizedEmail !== null && $normalizedEmail !== '') {
            return $normalizedEmail;
        }

        return null;
    }

    /**
     * Renders one public group route `/{group_prefix}/{group_slug}`.
     */
    public function group(string $groupSlug): void
    {
        $groupMode = $this->groupMode();
        $isLoggedIn = $this->auth->isLoggedIn();
        if ($this->groupRoutePrefix() === '') {
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

        $groupRouteData = $this->groups->findPublicRouteDataBySlug($normalizedSlug);
        if ($groupRouteData === null) {
            $this->notFound();
            return;
        }

        $group = is_array($groupRouteData['group'] ?? null) ? $groupRouteData['group'] : [];
        $members = is_array($groupRouteData['members'] ?? null) ? $groupRouteData['members'] : [];
        $members = $this->decorateGroupMembersForTemplate($members);
        $group = $this->decorateGroupForTemplate($group, $members);
        $template = match ($groupMode) {
            'public_full' => 'groups/list',
            'public_limited' => $isLoggedIn ? 'groups/list' : 'groups/limited',
            'private' => 'groups/list',
            default => 'groups/index',
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
        if ($this->auth->isLoggedIn() && $this->auth->canAccessPanel()) {
            \Raven\Core\Support\redirect($this->panelUrl('/'));
        }

        $this->renderPublic('login', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
            'flashSuccess' => $this->pullPublicFlash('success'),
            'flashError' => $this->pullPublicFlash('error'),
            'panelLoginPath' => $this->panelUrl('/login'),
            'registrationPath' => '/register',
            'registrationMode' => $this->registrationMode(),
            'loginIdentifierMode' => $this->loginIdentifierMode(),
            'loginIdentifierLabel' => $this->loginIdentifierMode() === 'email' ? 'Email' : 'Username',
        ], 'wrapper');
    }

    /**
     * Renders public registration page.
     */
    public function register(): void
    {
        $registrationMode = $this->registrationMode();
        $loginIdentifierMode = $this->loginIdentifierMode();
        $this->renderPublic('register', [
            'site' => $this->siteData(),
            'csrfField' => $this->csrf->field(),
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

        $registrationMode = $this->registrationMode();
        if ($registrationMode === 'closed') {
            $this->flashPublic('error', 'Registration is currently closed.');
            \Raven\Core\Support\redirect('/register');
        }

        $loginIdentifierMode = $this->loginIdentifierMode();
        $rawUsername = $this->input->text($post['username'] ?? null, 254);
        $normalizedUsername = $this->normalizeUserIdentifierValue($rawUsername);
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

        $usableInvite = null;
        $now = time();
        if ($registrationMode === 'invite') {
            if ($inviteToken === '') {
                $errors[] = 'Invite token is required in invite-only mode.';
            } else {
                $usableInvite = $this->inviteTokens->findUsableByToken($inviteToken, $now);
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
            $this->flashPublic('error', implode(' ', $errors));
            \Raven\Core\Support\redirect('/register');
        }

        $savedUserId = null;
        try {
            $savedUserId = $this->users->save([
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
            ]);

            if (is_array($usableInvite)) {
                $inviteId = (int) ($usableInvite['id'] ?? 0);
                $isReusable = (int) ($usableInvite['is_reusable'] ?? 0) === 1;
                if ($inviteId < 1 || !$this->inviteTokens->consume($inviteId, $isReusable, $now)) {
                    if (is_int($savedUserId) && $savedUserId > 0) {
                        try {
                            $this->users->deleteById($savedUserId);
                        } catch (\Throwable) {
                            // Keep original consume failure message.
                        }
                    }

                    $this->flashPublic('error', 'Invite token is no longer available. Please request a new token.');
                    \Raven\Core\Support\redirect('/register');
                }
            }
        } catch (\Throwable $exception) {
            $this->flashPublic('error', $exception->getMessage() ?: 'Failed to create account.');
            \Raven\Core\Support\redirect('/register');
        }

        $this->flashPublic('success', 'Account created. You can sign in if your account has dashboard access.');
        \Raven\Core\Support\redirect('/login');
    }

    /**
     * Renders profile-disabled/private-denied placeholder with explicit status.
     */
    private function renderProfileUnavailable(string $error, string $mode): void
    {
        if ($error === 'permission_denied') {
            http_response_code(403);
        } else {
            http_response_code(404);
        }

        $this->renderPublic('profiles/index', [
            'site' => $this->siteData(),
            'profile_show_denied' => $error === 'permission_denied' && $mode === 'private',
        ], 'wrapper');
    }

    /**
     * Renders group-route disabled/private-denied placeholder with explicit status.
     */
    private function renderGroupUnavailable(string $error, string $mode): void
    {
        if ($error === 'permission_denied') {
            http_response_code(403);
        } else {
            http_response_code(404);
        }

        $this->renderPublic('groups/index', [
            'site' => $this->siteData(),
            'group_show_denied' => $error === 'permission_denied' && $mode === 'private',
        ], 'wrapper');
    }

    /**
     * Returns default profile-contact option map (slug => metadata).
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function defaultProfileContactOptions(): array
    {
        return $this->profileContactService()->defaultOptions();
    }

    /**
     * Returns contact-option defaults that are mandatory and cannot be removed.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function requiredProfileContactOptions(): array
    {
        return $this->profileContactService()->requiredOptions();
    }

    /**
     * Normalizes one profile-contact option map from config.
     *
     * @return array<string, array{label: string, url_prefix: string}>
     */
    private function profileContactOptions(): array
    {
        return $this->profileContactService()->normalizeOptionsConfig(
            $this->config->get('user.contact', $this->defaultProfileContactOptions())
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
        return $this->profileContactService()->decorateProfileContacts($profile, $this->profileContactOptions());
    }

    /**
     * Resolves one optional URL/href for contact value + configured prefix.
     */
    private function resolveProfileContactHref(string $value, string $urlPrefix): ?string
    {
        return $this->profileContactService()->resolveProfileContactHref($value, $urlPrefix);
    }

    /**
     * Handles one public embedded-form submission request by type + slug.
     */
    public function submitEmbeddedForm(string $type, string $formSlug): void
    {
        $runtime = $this->embeddedFormRuntime($type);
        if ($runtime === null) {
            $this->notFound();
            return;
        }

        if (!$this->embeddedFormRuntimeService()->isRuntimeEnabled($runtime)) {
            $this->notFound();
            return;
        }

        $slug = $this->input->slug($formSlug);
        if ($slug === null) {
            $this->notFound();
            return;
        }

        $returnPath = $this->sanitizePublicReturnPath((string) ($_POST['return_path'] ?? '/'));

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
     * Returns one embedded-form runtime by shortcode type when available.
     */
    private function embeddedFormRuntime(string $type): ?EmbeddedFormRuntimeInterface
    {
        return $this->embeddedFormRuntimeService()->runtime($type, $this->embeddedFormRuntimes);
    }

    /**
     * Enforces global frontend availability mode before route handling.
     */
    public function enforceSiteAvailability(): bool
    {
        $mode = $this->siteEnabledMode();

        if ($mode === 'disabled') {
            if ($this->auth->isLoggedIn() && $this->auth->canViewDisabledSite()) {
                return true;
            }

            http_response_code(503);
            $this->renderPublic('messages/disabled', [
                'site' => $this->siteData(),
            ], 'wrapper');
            return false;
        }

        if ($mode === 'private') {
            if (!$this->auth->isLoggedIn() || !$this->auth->canViewPrivateSite()) {
                http_response_code(403);
                $this->renderPublic('messages/denied', [
                    'site' => $this->siteData(),
                ], 'wrapper');
                return false;
            }

            return true;
        }

        if (!$this->auth->canViewPublicSite()) {
            http_response_code(403);
            $this->renderPublic('messages/denied', [
                'site' => $this->siteData(),
            ], 'wrapper');
            return false;
        }

        return true;
    }

    /**
     * Renders public not-found page.
     */
    public function notFound(): void
    {
        http_response_code(404);

        $this->renderPublic('messages/404', [
            'site' => $this->siteData(),
        ], 'wrapper');
    }

    /**
     * Returns configured global frontend availability mode.
     */
    private function siteEnabledMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('site.enabled', 'public')));
        if (!in_array($mode, ['public', 'private', 'disabled'], true)) {
            return 'public';
        }

        return $mode;
    }

    /**
     * Collects site config values required by public templates.
     *
     * @return array<string, string>
     */
    private function siteData(): array
    {
        $publicTheme = $this->currentPublicThemeSlug();
        $configuredDomain = (string) $this->config->get('site.domain', 'localhost');
        $publicThemeCss = $this->currentPublicThemeCssSlug($publicTheme);

        return $this->siteContextBuilder()->publicBase(
            $this->config,
            $this->currentRequestUrl($configuredDomain),
            $publicTheme,
            $publicThemeCss,
            $this->absoluteMetaImageUrl(
                trim((string) $this->config->get('meta.twitter.image', '')),
                $configuredDomain
            ),
            $this->absoluteMetaImageUrl(
                trim((string) $this->config->get('meta.opengraph.image', '')),
                $configuredDomain
            )
        );
    }

    /**
     * Returns site data with page-level social metadata overrides when available.
     *
     * @param array<string, mixed> $page
     * @return array<string, string>
     */
    private function siteDataWithPageMeta(array $page): array
    {
        $site = $this->siteData();
        $site['twitter_creator'] = $this->resolvedTwitterCreatorForPage(
            $page,
            (string) ($site['twitter_creator'] ?? '')
        );

        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId < 1) {
            return $site;
        }

        $previewImageUrl = $this->absoluteMetaImageUrl(
            trim((string) ($this->pageImages->previewImageUrlForPage($pageId) ?? '')),
            (string) ($site['domain'] ?? 'localhost')
        );
        if ($previewImageUrl === '') {
            return $site;
        }

        $site['og_image'] = $previewImageUrl;
        $site['twitter_image'] = $previewImageUrl;

        return $site;
    }

    /**
     * Resolves effective `twitter:creator` for a page author with config fallback.
     *
     * @param array<string, mixed> $page
     */
    private function resolvedTwitterCreatorForPage(array $page, string $fallback): string
    {
        $fallback = trim($fallback);
        $authorUserId = (int) ($page['author_user_id'] ?? 0);
        if ($authorUserId < 1) {
            return $fallback;
        }

        $author = $this->users->findById($authorUserId);
        if (!is_array($author)) {
            return $fallback;
        }

        $profiles = is_array($author['contact_profiles'] ?? null) ? $author['contact_profiles'] : [];
        $creator = $this->twitterCreatorFromContactProfiles($profiles);
        return $creator !== '' ? $creator : $fallback;
    }

    /**
     * Extracts first valid Twitter/X creator handle from normalized contact-profile rows.
     *
     * @param array<int, array<string, mixed>> $profiles
     */
    private function twitterCreatorFromContactProfiles(array $profiles): string
    {
        return $this->profileContactService()->twitterCreatorFromProfiles(
            $profiles,
            $this->profileContactOptions()
        );
    }

    /**
     * Returns site data with taxonomy-level OG/Twitter image override when available.
     *
     * @param array<string, mixed> $taxonomy
     * @param array<string, string>|null $baseSiteData
     * @return array<string, string>
     */
    private function siteDataWithTaxonomyMetaImage(array $taxonomy, ?array $baseSiteData = null): array
    {
        $site = $baseSiteData ?? $this->siteData();
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

            $resolved = $this->absoluteMetaImageUrl($candidate, $configuredDomain);
            if ($resolved === '') {
                continue;
            }

            $site['og_image'] = $resolved;
            $site['twitter_image'] = $resolved;
            return $site;
        }

        return $site;
    }

    /**
     * Resolves one safe absolute URL for OpenGraph/Twitter image tag.
     *
     * Accepts absolute HTTP(S) URLs or local URL paths.
     */
    private function absoluteMetaImageUrl(string $value, string $configuredDomain): string
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
        $scheme = $this->resolveRequestScheme();
        $host = $this->resolveRequestHost($configuredDomain);

        return $scheme . '://' . $host . $path;
    }

    /**
     * Resolves active public theme slug from configuration + discovered manifests.
     */
    private function currentPublicThemeSlug(): string
    {
        $configured = strtolower($this->input->text((string) $this->config->get('site.default_theme', 'raven'), 80));
        $options = $this->publicThemeOptions();

        if (isset($options[$configured])) {
            return $configured;
        }

        if (isset($options['raven'])) {
            return 'raven';
        }

        $slugs = array_keys($options);
        return (string) ($slugs[0] ?? 'raven');
    }

    /**
     * Returns one canonical absolute URL for the current public request.
     */
    private function currentRequestUrl(string $configuredDomain): string
    {
        return $this->requestContextResolver()->currentRequestUrl($configuredDomain);
    }

    /**
     * Resolves request scheme from forwarded/proxy/server context.
     */
    private function resolveRequestScheme(): string
    {
        return $this->requestContextResolver()->resolveRequestScheme();
    }

    /**
     * Resolves one safe host[:port] for absolute URL generation.
     */
    private function resolveRequestHost(string $configuredDomain): string
    {
        return $this->requestContextResolver()->resolveRequestHost($configuredDomain);
    }

    /**
     * Returns discoverable public themes from `public/theme/{slug}/theme.json`.
     *
     * @return array<string, string>
     */
    private function publicThemeOptions(): array
    {
        $themesRoot = $this->publicThemesRoot();
        $options = PublicThemeRegistry::options($themesRoot);
        if ($options === []) {
            return ['raven' => 'Raven Basic'];
        }

        return $options;
    }

    /**
     * Returns filesystem root containing public themes.
     */
    private function publicThemesRoot(): string
    {
        return dirname(__DIR__, 3) . '/public/theme';
    }

    /**
     * Resolves active theme inheritance chain, child first.
     *
     * @return array<int, string>
     */
    private function currentPublicThemeInheritanceChain(string $themeSlug): array
    {
        $chain = PublicThemeRegistry::inheritanceChain($this->publicThemesRoot(), $themeSlug);
        if ($chain === []) {
            return [$themeSlug];
        }

        return $chain;
    }

    /**
     * Resolves one theme slug that provides the active public stylesheet.
     */
    private function currentPublicThemeCssSlug(string $themeSlug): string
    {
        foreach ($this->currentPublicThemeInheritanceChain($themeSlug) as $candidateThemeSlug) {
            $cssPath = $this->publicThemesRoot() . '/' . $candidateThemeSlug . '/css/style.css';
            if (is_file($cssPath)) {
                return $candidateThemeSlug;
            }
        }

        return $themeSlug;
    }

    /**
     * Normalizes and shortcode-renders repeatable Extended page blocks.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function renderPageExtendedBlocks(array $page): array
    {
        $page['content'] = $this->renderEmbeddedForms((string) ($page['content'] ?? ''));
        $rawBlocks = $page['extended_blocks'] ?? null;
        if (!is_array($rawBlocks)) {
            $rawBlocks = [];
        }

        $renderedBlocks = [];
        $hasGalleryBlock = false;
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
                $hasGalleryBlock = true;
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

        $page['extended_blocks'] = $renderedBlocks;
        if ($hasGalleryBlock) {
            $page['gallery_enabled'] = 1;
        }

        return $page;
    }

    /**
     * Returns true when at least one typed body block requests gallery output.
     *
     * @param array<string, mixed> $page
     */
    private function pageBodyIncludesGalleryBlock(array $page): bool
    {
        $rawBlocks = $page['extended_blocks'] ?? null;
        if (!is_array($rawBlocks)) {
            return false;
        }

        foreach ($rawBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if ($this->pageBodyBlockEditorMode((string) ($block['type'] ?? '')) === 'gallery') {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders one body block into public HTML.
     */
    private function renderPageBodyBlockByType(string $type, string $content): string
    {
        $type = $this->normalizePageBodyBlockType($type);
        $content = str_replace("\0", '', $content);

        return match ($this->pageBodyBlockEditorMode($type)) {
            'plaintext' => '<div class="raven-page-body-plaintext" style="white-space: pre-wrap;">'
                . $this->escapeHtml($content)
                . '</div>',
            'autobr' => '<div class="raven-page-body-autobr">'
                . $this->escapeNewlinesAsBreaks($content)
                . '</div>',
            'markdown' => $this->renderMarkdownBlockContent($content),
            'markdown_file' => $this->renderMarkdownFileBlock($content),
            'gallery' => '',
            default => $this->renderEmbeddedForms($content),
        };
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

        $root = dirname(__DIR__, 3);
        foreach (ExtensionRegistry::enabledDirectories($root, true) as $extensionName) {
            $manifest = ExtensionRegistry::readManifest($root, $extensionName);
            if (
                !is_array($manifest)
                || !in_array((string) ($manifest['type'] ?? ''), ['content', 'plugin', 'module'], true)
            ) {
                continue;
            }

            $fields = ExtensionRegistry::fields(
                $root,
                (string) $extensionName,
                [
                    'extension' => (string) $extensionName,
                ]
            );
            if ($fields === null) {
                continue;
            }

            $definitions = $this->bodyBlockPolicy()->normalizeExtensionDefinitions(
                (string) $extensionName,
                $fields,
                $definitions
            );
        }

        $this->pageBodyBlockTypeDefinitionsCache = $definitions;
        return $definitions;
    }

    /**
     * Renders markdown text block content into HTML.
     */
    private function renderMarkdownBlockContent(string $markdown): string
    {
        $html = $this->simpleMarkdownToHtml($markdown);
        if (trim($html) === '') {
            return '';
        }

        return $this->renderEmbeddedForms($html);
    }

    /**
     * Renders markdown from one local project path.
     */
    private function renderMarkdownFileBlock(string $pathInput): string
    {
        $markdown = $this->loadLocalMarkdownFileForBlock($pathInput);
        if ($markdown === null) {
            return '';
        }

        return $this->renderMarkdownBlockContent($markdown);
    }

    /**
     * Loads markdown content from one local path under project root.
     */
    private function loadLocalMarkdownFileForBlock(string $pathInput): ?string
    {
        $path = trim($pathInput);
        if ($path === '') {
            return null;
        }

        $path = (string) preg_replace('/[?#].*$/', '', $path);
        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        if (preg_match('/\.(?:md|markdown)$/i', $path) !== 1) {
            return null;
        }

        $projectRoot = realpath(dirname(__DIR__, 3));
        if (!is_string($projectRoot) || $projectRoot === '') {
            return null;
        }

        $projectRootPrefix = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $trimmedPath = trim($path);
        if ($trimmedPath === '') {
            return null;
        }

        $candidatePath = $projectRoot . '/' . ltrim($trimmedPath, '/');
        if ($candidatePath === '') {
            return null;
        }

        $resolved = realpath($candidatePath);
        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        if (!str_starts_with($resolved, $projectRootPrefix) || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $content = @file_get_contents($resolved, false, null, 0, 1048576);
        if (!is_string($content) || $content === '') {
            return null;
        }

        return str_replace("\0", '', $content);
    }

    /**
     * Converts markdown into basic safe HTML.
     */
    private function simpleMarkdownToHtml(string $markdown): string
    {
        return $this->markdownRenderer()->toHtml($markdown);
    }

    /**
     * Renders markdown inline tokens within one text fragment.
     */
    private function renderMarkdownInline(string $text): string
    {
        return $this->markdownRenderer()->renderInline($text);
    }

    /**
     * Normalizes one markdown link URL.
     */
    private function normalizeMarkdownLinkUrl(string $url): ?string
    {
        return $this->markdownRenderer()->normalizeLinkUrl($url);
    }

    /**
     * Escapes HTML while preserving UTF-8 text.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escapes text and converts newlines into `<br>` tag.
     */
    private function escapeNewlinesAsBreaks(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        return nl2br($this->escapeHtml($normalized), false);
    }

    /**
     * Returns configured category-list route prefix.
     */
    private function categoryRoutePrefix(): string
    {
        return $this->routeConfigService()->categoryRoutePrefix();
    }

    /**
     * Returns configured tag-list route prefix.
     */
    private function tagRoutePrefix(): string
    {
        return $this->routeConfigService()->tagRoutePrefix();
    }

    /**
     * Returns true when category taxonomy is enabled.
     */
    private function categoryEnabled(): bool
    {
        return $this->routeConfigService()->categoryEnabled();
    }

    /**
     * Returns true when tag taxonomy is enabled.
     */
    private function tagEnabled(): bool
    {
        return $this->routeConfigService()->tagEnabled();
    }

    /**
     * Normalizes one config scalar to a boolean value.
     */
    private function configBool(mixed $value, bool $default = false): bool
    {
        return $this->routeConfigService()->configBool($value, $default);
    }

    /**
     * Returns configured public profile route prefix.
     */
    private function profileRoutePrefix(): string
    {
        return $this->routeConfigService()->profileRoutePrefix();
    }

    /**
     * Returns configured public profile mode.
     */
    private function profileMode(): string
    {
        return $this->routeConfigService()->profileMode();
    }

    /**
     * Returns configured public group route prefix.
     */
    private function groupRoutePrefix(): string
    {
        return $this->routeConfigService()->groupRoutePrefix();
    }

    /**
     * Returns configured public group mode.
     */
    private function groupMode(): string
    {
        return $this->routeConfigService()->groupMode();
    }

    /**
     * Resolves configured registration mode.
     */
    private function registrationMode(): string
    {
        return $this->routeConfigService()->registrationMode();
    }

    /**
     * Resolves configured panel login identifier mode.
     */
    private function loginIdentifierMode(): string
    {
        return $this->identifierResolver->modeFromConfig($this->config);
    }

    /**
     * Returns registration default group ids.
     *
     * @return array<int>
     */
    private function registrationGroupIds(): array
    {
        foreach (['user', 'guest', 'validating'] as $slug) {
            $groupId = $this->groups->idBySlug($slug);
            if (is_int($groupId) && $groupId > 0) {
                return [$groupId];
            }
        }

        return [];
    }

    /**
     * Normalizes one user identifier (username or email-shaped value).
     */
    private function normalizeUserIdentifierValue(string $rawValue): ?string
    {
        return $this->identifierResolver->normalizeUsernameOrEmail($this->input, $rawValue);
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

    private function requestContextResolver(): RequestContextResolver
    {
        if (!$this->requestContextResolver instanceof RequestContextResolver) {
            $this->requestContextResolver = new RequestContextResolver();
        }

        return $this->requestContextResolver;
    }

    private function publicTemplateResolver(): PublicTemplateResolver
    {
        if (!$this->publicTemplateResolver instanceof PublicTemplateResolver) {
            $this->publicTemplateResolver = new PublicTemplateResolver($this->input);
        }

        return $this->publicTemplateResolver;
    }

    private function embeddedFormRuntimeService(): EmbeddedFormRuntimeService
    {
        if (!$this->embeddedFormRuntimeService instanceof EmbeddedFormRuntimeService) {
            $this->embeddedFormRuntimeService = new EmbeddedFormRuntimeService($this->input, dirname(__DIR__, 3));
        }

        return $this->embeddedFormRuntimeService;
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

    /**
     * Normalizes one taxonomy route-prefix value and falls back safely.
     */
    private function normalizeTaxonomyRoutePrefix(string $configured, string $fallback, bool $allowBlank = false): string
    {
        return $this->routeConfigService()->normalizeRoutePrefix($configured, $fallback, $allowBlank);
    }

    /**
     * Expands pagination payload into template-ready link rows.
     *
     * @param array<string, mixed> $pagination
     * @return array<string, mixed>
     */
    private function decoratePaginationForTemplate(array $pagination): array
    {
        return Pagination::decorateTemplateLinks($pagination);
    }

    /**
     * Adds template-friendly derived keys for page render views.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function decoratePageForTemplate(array $page): array
    {
        $page['display_title_resolved'] = !array_key_exists('display_title', $page)
            || (int) ($page['display_title'] ?? 1) === 1;

        $rawBlocks = is_array($page['extended_blocks'] ?? null) ? $page['extended_blocks'] : [];
        $hasBodyContent = trim((string) ($page['content'] ?? '')) !== '';
        $renderedBlocks = [];
        $displayIndex = 0;

        foreach ($rawBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $html = trim((string) ($block['html'] ?? ''));
            if ($html === '') {
                continue;
            }

            $displayIndex++;
            $classNames = [
                'raven-page-extended-block',
                'raven-page-extended-block-' . $displayIndex,
            ];

            if ($hasBodyContent || $displayIndex > 1) {
                array_unshift($classNames, 'mt-3');
            }

            $customClass = trim((string) ($block['css_class'] ?? ''));
            if ($customClass !== '') {
                $classNames[] = $customClass;
            }

            $renderedBlocks[] = [
                'html' => $html,
                'css_id' => trim((string) ($block['css_id'] ?? '')),
                'class' => trim(implode(' ', $classNames)),
            ];
        }

        $page['extended_blocks'] = $renderedBlocks;
        return $page;
    }

    /**
     * Adds template-ready URL fields for gallery image rows.
     *
     * @param array<int, array<string, mixed>> $galleryImages
     * @return array<int, array<string, mixed>>
     */
    private function decorateGalleryImagesForTemplate(array $galleryImages): array
    {
        foreach ($galleryImages as $index => $image) {
            if (!is_array($image)) {
                continue;
            }

            $variants = is_array($image['variants'] ?? null) ? $image['variants'] : [];
            $imageUrl = trim((string) (($variants['md']['url'] ?? '') ?: ($image['url'] ?? '')));
            $fullUrl = trim((string) (($variants['lg']['url'] ?? '') ?: $imageUrl));

            $galleryImages[$index]['image_url'] = $imageUrl;
            $galleryImages[$index]['full_url'] = $fullUrl;
            $galleryImages[$index]['alt_text'] = (string) ($image['alt_text'] ?? '');
            $galleryImages[$index]['caption'] = (string) ($image['caption'] ?? '');
        }

        return $galleryImages;
    }

    /**
     * Adds template-friendly derived keys to one public profile payload.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function decorateProfileForTemplate(array $profile): array
    {
        $displayName = trim((string) ($profile['display_name'] ?? ''));
        $username = trim((string) ($profile['username'] ?? ''));
        $profile['display_name_resolved'] = $displayName !== '' ? $displayName : $username;

        $avatar = $this->avatarTemplateDataFromPath((string) ($profile['avatar_path'] ?? ''));
        $profile['avatar_filename'] = $avatar['filename'];
        $profile['avatar_url'] = $avatar['url'];
        $profile['avatar_thumb_url'] = $avatar['thumb_url'];
        $profile['has_avatar'] = $avatar['filename'] !== '';

        $contacts = is_array($profile['contact_profiles'] ?? null) ? $profile['contact_profiles'] : [];
        foreach ($contacts as $index => $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $href = trim((string) ($contact['href'] ?? ''));
            $contacts[$index]['is_external'] = preg_match('#^https?://#i', $href) === 1;
        }
        $profile['contact_profiles'] = $contacts;

        return $profile;
    }

    /**
     * Adds template-friendly member rows for group list templates.
     *
     * @param array<int, array<string, mixed>> $members
     * @return array<int, array<string, mixed>>
     */
    private function decorateGroupMembersForTemplate(array $members): array
    {
        foreach ($members as $index => $member) {
            if (!is_array($member)) {
                continue;
            }

            $displayName = trim((string) ($member['display_name'] ?? ''));
            $username = trim((string) ($member['username'] ?? ''));
            $members[$index]['display_name_resolved'] = $displayName !== '' ? $displayName : $username;

            $avatar = $this->avatarTemplateDataFromPath((string) ($member['avatar_path'] ?? ''));
            $members[$index]['avatar_filename'] = $avatar['filename'];
            $members[$index]['avatar_url'] = $avatar['url'];
            $members[$index]['avatar_thumb_url'] = $avatar['thumb_url'];
            $members[$index]['has_avatar'] = $avatar['filename'] !== '';
        }

        return $members;
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
        $group['member_count_resolved'] = max(count($members), (int) ($group['member_count'] ?? 0));
        return $group;
    }

    /**
     * Builds template-facing avatar URL values from stored avatar path.
     *
     * @return array{filename: string, url: string, thumb_url: string}
     */
    private function avatarTemplateDataFromPath(string $avatarPath): array
    {
        $avatarFilename = basename(trim($avatarPath));
        if ($avatarFilename === '') {
            return ['filename' => '', 'url' => '', 'thumb_url' => ''];
        }

        $avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
        $avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;

        return [
            'filename' => $avatarFilename,
            'url' => '/uploads/avatars/' . rawurlencode($avatarFilename),
            'thumb_url' => '/uploads/avatars/' . rawurlencode($avatarThumbFilename),
        ];
    }

    /**
     * Injects shared wrapper metadata derived from route payloads.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function decorateTemplateData(array $data): array
    {
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];

        $siteName = trim((string) ($site['name'] ?? 'Raven CMS'));
        if ($siteName === '') {
            $siteName = 'Raven CMS';
        }
        $site['name'] = $siteName;

        $publicThemeCss = trim((string) ($site['public_theme_css'] ?? $site['public_theme'] ?? 'raven'));
        if ($publicThemeCss === '') {
            $publicThemeCss = 'raven';
        }
        $site['public_theme_css'] = $publicThemeCss;

        if (trim((string) ($site['twitter_site'] ?? '')) === '') {
            $site['twitter_site'] = $siteName;
        }
        if (trim((string) ($site['og_type'] ?? '')) === '') {
            $site['og_type'] = 'website';
        }
        if (trim((string) ($site['og_locale'] ?? '')) === '') {
            $site['og_locale'] = 'en_US';
        }

        $viewTitle = '';
        $metaDescription = '';
        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
        $pageNumber = max(1, (int) ($pagination['current'] ?? 1));

        if (is_array($data['page'] ?? null)) {
            $viewTitle = trim((string) ($data['page']['title'] ?? ''));
            $metaDescription = trim((string) ($data['page']['description'] ?? ''));
        } elseif (is_array($data['category'] ?? null)) {
            $categoryName = trim((string) ($data['category']['name'] ?? ''));
            if ($categoryName !== '') {
                $viewTitle = 'Category: ' . $categoryName;
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = trim((string) ($data['category']['description'] ?? ''));
                if ($metaDescription === '') {
                    $metaDescription = 'Browse pages in category ' . $categoryName . '.';
                }
            }
        } elseif (is_array($data['tag'] ?? null)) {
            $tagName = trim((string) ($data['tag']['name'] ?? ''));
            if ($tagName !== '') {
                $viewTitle = 'Tag: ' . $tagName;
                if ($pageNumber > 1) {
                    $viewTitle .= ' (Page ' . $pageNumber . ')';
                }

                $metaDescription = 'Browse pages tagged ' . $tagName . '.';
            }
        } elseif (is_array($data['profile'] ?? null)) {
            $profileName = trim((string) ($data['profile']['display_name_resolved'] ?? $data['profile']['display_name'] ?? ''));
            if ($profileName === '') {
                $profileName = trim((string) ($data['profile']['username'] ?? ''));
            }
            if ($profileName !== '') {
                $viewTitle = 'Profile: ' . $profileName;
                $metaDescription = 'Public profile for ' . $profileName . '.';
            }
        } elseif (is_array($data['group'] ?? null)) {
            $groupName = trim((string) ($data['group']['name'] ?? ''));
            if ($groupName !== '') {
                $viewTitle = 'Group: ' . $groupName;
                $metaDescription = 'Members in group ' . $groupName . '.';
            }
        }

        if ($viewTitle === '' && http_response_code() === 404) {
            $viewTitle = 'Not Found';
            if ($metaDescription === '') {
                $metaDescription = 'The requested page could not be found.';
            }
        }

        $documentTitle = $viewTitle === '' ? $siteName : ($viewTitle . ' [' . $siteName . ']');

        $data['site'] = $site;
        $data['view_meta'] = [
            'title' => $viewTitle,
            'description' => $metaDescription,
            'document_title' => $documentTitle,
        ];

        return $data;
    }

    /**
     * Renders one public template with theme-aware lookup and private fallback.
     *
     * Theme lookup order:
     * 1) `public/theme/{active_theme}/vis/{template}.php`
     * 2) `private/vis/{template}.php`
     *
     * @param array<string, mixed> $data
     */
    private function renderPublic(string $template, array $data = [], ?string $layout = null): void
    {
        $data = $this->decorateTemplateData($data);

        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/vis';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];

        $templateFile = $this->resolvePublicTemplateFile($template, ...$lookupRoots);
        if ($templateFile === null) {
            throw new \RuntimeException('Public template not found: ' . $template);
        }

        $content = $this->renderPublicTemplateFile($templateFile, $data);
        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = $this->resolvePublicTemplateFile($layout, ...$lookupRoots);
        if ($layoutFile === null) {
            throw new \RuntimeException('Public layout not found: ' . $layout);
        }

        $layoutData = $data;
        $layoutData['content'] = $content;
        echo $this->renderPublicTemplateFile($layoutFile, $layoutData);
    }

    /**
     * Returns active public theme views roots, child first.
     *
     * @return array<int, string>
     */
    private function currentPublicThemeViewsRoots(): array
    {
        return $this->publicTemplateResolver()->currentThemeViewsRoots(
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug()
        );
    }

    /**
     * Resolves one public template path from ordered roots.
     */
    private function resolvePublicTemplateFile(string $template, string ...$roots): ?string
    {
        return $this->publicTemplateResolver()->resolveTemplateFile($template, ...$roots);
    }

    /**
     * Resolves channel landing template name with slug-specific override support.
     *
     * Priority:
     * 1) `vis/channels/{channel_slug}.php`
     * 2) `vis/channels/index.php`
     */
    private function resolveChannelTemplateName(string $channelSlug): string
    {
        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/vis';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];
        return $this->publicTemplateResolver()->resolveChannelTemplateName($channelSlug, ...$lookupRoots);
    }

    /**
     * Resolves public page template with optional channel-specific override.
     *
     * Priority:
     * 1) `vis/pages/{channel_slug}.php` when route has a channel
     * 2) `vis/pages/index.php`
     */
    private function resolvePageTemplateName(?string $channelSlug): string
    {
        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/vis';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];
        return $this->publicTemplateResolver()->resolvePageTemplateName($channelSlug, ...$lookupRoots);
    }

    /**
     * Resolves category-list template name with category-slug override support.
     *
     * Priority:
     * 1) `vis/categories/{category_slug}.php`
     * 2) `vis/categories/index.php`
     */
    private function resolveCategoryTemplateName(string $categorySlug): string
    {
        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/vis';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];
        return $this->publicTemplateResolver()->resolveCategoryTemplateName($categorySlug, ...$lookupRoots);
    }

    /**
     * Resolves tag-list template name with tag-slug override support.
     *
     * Priority:
     * 1) `vis/tags/{tag_slug}.php`
     * 2) `vis/tags/index.php`
     */
    private function resolveTagTemplateName(string $tagSlug): string
    {
        $themeViewsRoots = $this->currentPublicThemeViewsRoots();
        $coreViewsRoot = dirname(__DIR__, 3) . '/private/vis';
        $lookupRoots = [...$themeViewsRoots, $coreViewsRoot];
        return $this->publicTemplateResolver()->resolveTagTemplateName($tagSlug, ...$lookupRoots);
    }

    /**
     * Executes one resolved public template file in isolated scope.
     *
     * @param array<string, mixed> $data
     */
    private function renderPublicTemplateFile(string $file, array $data): string
    {
        return $this->templateTags->renderFile($file, $data);
    }

    /**
     * Normalizes a post-submit return path to one safe local absolute path.
     */
    private function sanitizePublicReturnPath(string $rawPath): string
    {
        return $this->embeddedFormRuntimeService()->sanitizeReturnPath($rawPath);
    }

    /**
     * Returns normalized client IP when present and valid.
     */
    private function normalizeClientIp(string $rawIp): ?string
    {
        return $this->requestContextResolver()->normalizeClientIp($rawIp);
    }

    /**
     * Resolves reverse-DNS hostname for one normalized client IP.
     */
    private function resolveClientHostname(?string $ipAddress): ?string
    {
        return $this->requestContextResolver()->resolveClientHostname($ipAddress);
    }

    /**
     * Verifies configured public captcha response in current request.
     *
     * @return string|null One user-facing validation error, or null when captcha passes.
     */
    private function validatePublicCaptcha(): ?string
    {
        $remoteIp = $this->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
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
     * Resolves supported form shortcodes inside editor HTML content.
     */
    private function renderEmbeddedForms(string $html): string
    {
        return $this->embeddedFormRuntimeService()->renderShortcodes(
            $html,
            $this->embeddedFormRuntimes,
            fn (string $type, array $definition): string => $this->embeddedFormMarkup($type, $definition)
        );
    }

    /**
     * Builds public HTML markup for one embedded form definition.
     *
     * @param array<string, mixed> $definition
     */
    private function embeddedFormMarkup(string $type, array $definition): string
    {
        $runtime = $this->embeddedFormRuntime($type);
        if ($runtime === null) {
            return '';
        }

        $returnPath = $this->sanitizePublicReturnPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        return $runtime->render(
            $definition,
            $returnPath,
            $this->csrf->field(),
            $this->publicCaptchaMarkup()
        );
    }

}
