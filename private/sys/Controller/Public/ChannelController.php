<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/ChannelController.php
 * Split public channel controller for single-segment channel routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Closure;
use Raven\Core\Debug\ClientProfiler;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\FeedPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Lib\Extension\Public\Content as ExtensionContent;
use Raven\Lib\Extension\Public\FormInstance as ExtensionFormInstance;
use Raven\Lib\Extension\Public\FormRuntime as ExtensionFormRuntime;
use Raven\Lib\Extension\Public\Shortcodes as ExtensionShortcodes;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\RedirectParser;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Security\PublicCaptchaFlow;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Transport\Request;
use Raven\Lib\View\Public\Meta;
use Raven\Lib\View\Public\PageBlocks;
use Raven\Lib\View\Public\PageMarkdown;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Handles split public single-segment channel routes.
 *
 * This seam owns `/{slug}` lookups. It renders a channel landing page when the
 * slug belongs to a channel with a homepage, otherwise it falls back to the
 * same root-page and redirect resolution that used to live behind the older
 * mixed page-controller single-segment route without calling into another controller.
 */
final class ChannelController
{
    private SharedController $context;
    private ChannelRead $channelRead;
    private MediaRead $media;
    private PageRead $pageRead;
    private RedirectRead $redirectRead;
    private UserRead $userRead;
    private Closure $extensionServicesProvider;
    /** @var array<string, ExtensionShortcodes|ExtensionFormRuntime> */
    private array $shortcodeRuntimes = [];
    private bool $shortcodeRuntimesLoaded = false;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $blockTypeDefsCache = null;
    private ThemeCatalog $themeCatalog;
    private ?ThemeTemplate $themeTemplate = null;
    private ?Meta $metaService = null;
    private ?TemplateDecorator $templateDecorator = null;
    private ?PageMarkdown $pageMarkdown = null;
    private ?PageBlockParser $pageBlockParser = null;
    private ?PageBlocks $pageBlocks = null;
    private ExtensionContent $extensionContent;
    private ?ExtensionFormInstance $formInstance = null;
    private ?UserProfileParser $profileParser = null;
    private Request $request;
    private FeedPolicy $feedParser;
    private ClientProfiler $clientProfiler;
    private PublicCaptchaFlow $publicCaptchaFlow;

    /**
     * @param SharedController $context Shared public request context.
     * @param ChannelRead $channelRead Channel repository read side for parent-aware public paths.
     * @param MediaRead $media Media repository read side for gallery rendering and page meta images.
     * @param PageRead $pageRead Page repository read side for channel-homepage and root-page lookups.
     * @param RedirectRead $redirectRead Redirect repository read side for public redirect fallbacks.
     * @param UserRead $userRead User repository read side for author profile lookups in page meta.
     * @param ThemeCatalog $themeCatalog Shared public-theme catalog for template resolution and meta reads.
     * @param ExtensionContent $extensionContent Shared extension editor catalog for public block definitions.
     * @param callable(?string=): array<string, mixed> $extensionServicesProvider Lazy extension-services resolver for shortcode runtimes.
     * @return void
     */
    public function __construct(
        SharedController $context,
        ChannelRead $channelRead,
        MediaRead $media,
        PageRead $pageRead,
        RedirectRead $redirectRead,
        UserRead $userRead,
        ThemeCatalog $themeCatalog,
        ExtensionContent $extensionContent,
        callable $extensionServicesProvider
    ) {
        $this->context = $context;
        $this->channelRead = $channelRead;
        $this->media = $media;
        $this->pageRead = $pageRead;
        $this->redirectRead = $redirectRead;
        $this->userRead = $userRead;
        $this->themeCatalog = $themeCatalog;
        $this->extensionContent = $extensionContent;
        $this->extensionServicesProvider = Closure::fromCallable($extensionServicesProvider);
        $this->request = new Request();
        $this->feedParser = new FeedPolicy($context->config(), $context->input());
        $this->clientProfiler = new ClientProfiler();
        $this->publicCaptchaFlow = new PublicCaptchaFlow(
            $context->config(),
            $context->input(),
            $this->clientProfiler
        );
    }

    /**
     * Returns whether a complete public channel path resolves through direct parents.
     *
     * @param string $channelPath Slash-separated channel path to test.
     * @return bool True when the path identifies a non-root channel.
     */
    public function channelPathExists(string $channelPath): bool
    {
        return $this->channelRead->findByPath($channelPath) !== null;
    }

    /**
     * Resolves one public channel route by its parent-aware path.
     *
     * Channel landing pages keep priority on this route shape. When a channel
     * does not exist or has no landing page, the same slug is reinterpreted as
     * a root-scope page route before falling through to redirects and 404s.
     *
     * @param string $channelSlug Normalized parent-aware channel path.
     * @return void
     */
    public function channel(string $channelSlug): void
    {
        $requestedRouteSegment = strtolower(trim($channelSlug));
        $requestedSlug = PagePolicy::stripPeriodSuffix($requestedRouteSegment);
        // Empty single-segment routes cannot resolve to channel or page resources.
        if ($requestedSlug === '') {
            $this->context->notFound();
            return;
        }

        // findChannelHomepage() returns null when the channel does not exist, or a
        // ['channel' => ..., 'page' => ...] tuple — page is null when no homepage exists.
        $result = $this->pageRead->findChannelHomepage($requestedSlug);
        // Channel landing page path renders when homepage tuple includes page payload.
        if (is_array($result) && is_array($result['page'] ?? null)) {
            $channel = is_array($result['channel'] ?? null) ? $result['channel'] : [];
            $canonicalPath = $this->channelRead->pathForChannel((int) ($channel['id'] ?? 0));
            $globalRouteMode = ChannelPolicy::globalPageRouteSelector($this->context->config());
            $canonicalPublicPath = PagePolicy::canonicalPath(
                '/' . $this->encodePath($canonicalPath),
                ChannelPolicy::siteRoutingUsesTrailingSlash($this->context->config())
            );
            // File-looking aliases canonicalize to the extensionless parent-aware path.
            if (
                ($canonicalPath !== '' && strcasecmp($requestedSlug, $canonicalPath) !== 0)
                || strcasecmp($requestedRouteSegment, $requestedSlug) !== 0
                || $this->request->hasTrailingSlash() !== ChannelPolicy::siteRoutingUsesTrailingSlash($this->context->config())
            ) {
                Redirect::redirect($canonicalPublicPath, 301);
            }

            $page = $this->renderPageContentBlocks($result['page']);
            $page = $this->templateDecorator()->decoratePageForTemplate($page);
            $channelTheme = $this->channelThemeSlug($channel);

            $channelTemplate = $this->themeTemplate()->resolveChannelTemplateNameForThemeChain(
                (string) ($channel['slug'] ?? ''),
                $this->themesRoot(),
                $channelTheme,
                dirname(__DIR__, 4) . '/private/tpl/public'
            );

            $site = $this->siteDataWithPageMeta($page, $channelTheme);
            // Channel-level cover/preview uploads override page/default meta images on channel landings.
            $site = $this->metaService()->siteDataWithTaxonomyMetaImage($channel, $site);

            $this->context->renderPublic($channelTemplate, [
                'site' => $site,
                'channel' => $channel,
                'page' => $page,
            ], 'wrapper', $channelTheme);
            return;
        }

        $channelRouteMode = ChannelPolicy::globalPageRouteSelector($this->context->config());
        $lookupTarget = PagePolicy::resolveLookupTarget($this->context->input(), 
            $requestedSlug,
            $channelRouteMode,
            (string) $this->context->config()->get('content.separator', '-')
        );
        // Invalid lookup targets fall through to redirect-or-404 behavior.
        if (!is_array($lookupTarget)) {
            // Redirect mapping has priority before 404 on unresolved route targets.
            if ($this->tryRedirect($requestedSlug, null)) {
                return;
            }

            $this->context->notFound();
            return;
        }

        $page = null;
        // Route mode chooses id-based or slug-based page lookup target.
        if ((string) ($lookupTarget['type'] ?? '') === 'id') {
            $page = $this->pageRead->findPublishedById((int) ($lookupTarget['id'] ?? 0), null);
        } else {
            $lookupSlug = (string) ($lookupTarget['slug'] ?? $requestedSlug);
            $page = $this->pageRead->findPublishedBySlug($lookupSlug, null);
        }

        // Missing pages fall through to redirect-or-404 behavior.
        if ($page === null) {
            // Redirect mapping has priority before 404 on missing page records.
            if ($this->tryRedirect($requestedSlug, null)) {
                return;
            }

            $this->context->notFound();
            return;
        }

        $canonicalSegment = PagePolicy::buildRouteSegment($this->context->input(), 
            (string) ($page['slug'] ?? ''),
            (int) ($page['id'] ?? 0),
            (string) ($page['created'] ?? ''),
            $channelRouteMode,
            'inherit',
            (string) $this->context->config()->get('content.separator', '-')
        );
        $canonicalPath = PagePolicy::canonicalPath(
            '/' . rawurlencode($canonicalSegment),
            ChannelPolicy::siteRoutingUsesTrailingSlash($this->context->config())
        );
        // Redirect to canonical segment and slash policy when the requested path differs.
        if (
            ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedRouteSegment) !== 0)
            || $this->request->hasTrailingSlash() !== ChannelPolicy::siteRoutingUsesTrailingSlash($this->context->config())
        ) {
            Redirect::redirect($canonicalPath, 301);
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->templateDecorator()->decoratePageForTemplate($page);
        $pageTemplate = $this->themeTemplate()->resolvePageTemplateNameForThemeChain(
            null,
            $this->themesRoot(),
            $this->activeThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl/public'
        );

        $this->context->renderPublic($pageTemplate, [
            'site' => $this->siteDataWithPageMeta($page),
            'channel' => null,
            'page' => $page,
        ], 'wrapper');
    }

    /**
     * Returns site data with page-level social metadata overrides when available.
     *
     * @param array<string, mixed> $page Public page payload.
     * @param string|null $themeOverride Effective channel theme slug, or null for global theme.
     * @return array<string, mixed> Site metadata payload with page overrides.
     */
    private function siteDataWithPageMeta(array $page, ?string $themeOverride = null): array
    {
        $profileContactOptions = $this->profileParser()->normalizeOptionsConfig(
            $this->context->config()->get('user.contact', $this->profileParser()->defaultOptions())
        );

        return $this->metaService()->siteDataWithPageMeta(
            $page,
            $this->context->siteData($themeOverride),
            fn (int $pageId): ?string => $this->media->coverLargeVariantUrlForPage($pageId),
            fn (int $authorUserId): ?array => $this->userRead->findById($authorUserId),
            $profileContactOptions
        );
    }

    /**
     * Normalizes and shortcode-renders repeatable page content blocks.
     *
     * @param array<string, mixed> $page Public page payload.
     * @return array<string, mixed> Public page payload with rendered content blocks.
     */
    private function renderPageContentBlocks(array $page): array
    {
        return $this->pageBlocks()->renderPageContentBlocks(
            $page,
            $this->blockTypeDefinitions(),
            fn (): string => $this->renderPageGalleryBlockHtml($page),
            fn (string $html): string => $this->renderFormShortcodes($html)
        );
    }

    /**
     * Renders one gallery body block from page media rows.
     *
     * @param array<string, mixed> $page Public page payload.
     * @return string Gallery block HTML.
     */
    private function renderPageGalleryBlockHtml(array $page): string
    {
        $pageId = (int) ($page['id'] ?? 0);
        // Gallery rendering requires a valid persisted page id.
        if ($pageId <= 0) {
            return '';
        }

        $galleryImages = $this->templateDecorator()->decorateGalleryImagesForTemplate(
            $this->media->listDisplayReadyForPage($pageId)
        );
        // Empty gallery sets yield no gallery block markup.
        if ($galleryImages === []) {
            return '';
        }

        $items = [];
        // Normalize gallery rows into template-ready image item payloads.
        foreach ($galleryImages as $image) {
            // Ignore malformed gallery rows.
            if (!is_array($image)) {
                continue;
            }

            $imageUrl = trim((string) ($image['image_url'] ?? ''));
            $fullUrl = trim((string) ($image['full_url'] ?? ''));
            // Skip rows missing both preview and full image URLs.
            if ($imageUrl === '' && $fullUrl === '') {
                continue;
            }

            // Fall back preview URL to full URL when thumbnail URL is absent.
            if ($imageUrl === '') {
                $imageUrl = $fullUrl;
            }
            // Fall back full URL to preview URL when full URL is absent.
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

        // No valid image items means no gallery markup should be rendered.
        if ($items === []) {
            return '';
        }

        return '<section class="mt-4"><div class="row g-3">' . implode('', $items) . '</div></section>';
    }

    /**
     * Escapes one gallery HTML fragment for safe output.
     *
     * @param string $value Raw gallery value.
     * @return string HTML-escaped value.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Returns public page body-block type definitions.
     *
     * @return array<string, array{label: string, editor: string}> Body-block definitions keyed by type.
     */
    private function blockTypeDefinitions(): array
    {
        // Reuse cached body-block definitions for this request.
        if (is_array($this->blockTypeDefsCache)) {
            return $this->blockTypeDefsCache;
        }

        $this->blockTypeDefsCache = $this->pageBlocks()->mergeTypeDefinitions(
            $this->extensionContent->publicBodyBlockDefinitions()
        );

        return $this->blockTypeDefsCache;
    }

    /**
     * Attempts active redirect lookup for a URL path and emits HTTP redirect when found.
     *
     * @param string $pageSlug Requested page slug segment.
     * @param string|null $channelSlug Optional channel slug.
     * @return bool True when a redirect response was emitted.
     */
    private function tryRedirect(string $pageSlug, ?string $channelSlug = null): bool
    {
        $redirectRow = $this->redirectRead->findActiveByPath($pageSlug, $channelSlug);
        // No matching redirect row means caller should continue normal routing.
        if ($redirectRow === null) {
            return false;
        }

        $targetUrl = trim((string) ($redirectRow['target'] ?? ''));
        // Reject unsafe/malformed redirect targets.
        if (!RedirectParser::isAllowedHttpOrRootPath($targetUrl)) {
            return false;
        }

        // Redirect targets should converge directly on the configured public slash policy.
        $targetUrl = PagePolicy::canonicalRedirectTarget(
            $targetUrl,
            ChannelPolicy::siteRoutingUsesTrailingSlash($this->context->config())
        );

        // Default behavior remains temporary until route status configuration is introduced.
        Redirect::redirect($targetUrl, 302);
        return true;
    }

    /**
     * Resolves supported form shortcodes inside editor HTML content.
     *
     * @param string $html Raw HTML containing potential shortcode markers.
     * @return string HTML with supported shortcodes expanded.
     */
    private function renderFormShortcodes(string $html): string
    {
        return $this->formInstance()->renderPublicShortcodes(
            $html,
            $this->shortcodeRuntimes(),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $this->context->csrf()->field(),
            fn (): string => $this->publicCaptchaFlow->markup()
        );
    }

    /**
     * Returns the embedded-form runtime service for the current request.
     *
     * @return ExtensionFormInstance Shared embedded-form runtime service.
     */
    private function formInstance(): ExtensionFormInstance
    {
        // Lazily initialize embedded-form runtime service.
        if (!$this->formInstance instanceof ExtensionFormInstance) {
            $this->formInstance = new ExtensionFormInstance(
                $this->context->input(),
                dirname(__DIR__, 4)
            );
        }

        return $this->formInstance;
    }

    /**
     * Returns the discovered embedded shortcode/form runtimes for the current request.
     *
     * @return array<string, ExtensionShortcodes|ExtensionFormRuntime> Runtime map keyed by shortcode type.
     */
    private function shortcodeRuntimes(): array
    {
        // Discover shortcode runtimes once per request.
        if (!$this->shortcodeRuntimesLoaded) {
            $this->shortcodeRuntimes = $this->formInstance()->discoverRuntimes($this->extensionServices());
            $this->shortcodeRuntimesLoaded = true;
        }

        return $this->shortcodeRuntimes;
    }

    /**
     * Returns the extension-services map, booting extensions only when content shortcodes need them.
     *
     * @return array<string, mixed> Public extension-services map.
     */
    private function extensionServices(): array
    {
        /** @var mixed $services */
        $services = ($this->extensionServicesProvider)();
        return is_array($services) ? $services : [];
    }
    /**
     * Returns the shared public theme-template service.
     *
     * @return ThemeTemplate Shared public theme-template service.
     */
    private function themeTemplate(): ThemeTemplate
    {
        // Lazily initialize theme-template resolver.
        if (!$this->themeTemplate instanceof ThemeTemplate) {
            $this->themeTemplate = new ThemeTemplate($this->context->input());
        }

        return $this->themeTemplate;
    }

    /**
     * Returns the active public theme slug.
     *
     * @return string Active public theme slug.
     */
    private function activeThemeSlug(): string
    {
        return $this->themeCatalog->activeSlugFromConfig($this->context->config());
    }

    /**
     * Resolves the effective public theme for one channel.
     *
     * Missing or removed overrides intentionally return the global active theme so legacy
     * channel records continue through the same child/parent/core fallback path.
     *
     * @param array<string, mixed> $channel Channel record carrying the stored theme override.
     * @return string Effective installed public-theme slug.
     */
    private function channelThemeSlug(array $channel): string
    {
        return $this->themeCatalog->resolveOverrideSlug(
            (string) ($channel['theme_override'] ?? 'inherit'),
            $this->context->config()
        );
    }

    /**
     * Returns the filesystem root containing public themes.
     *
     * @return string Absolute public theme root.
     */
    private function themesRoot(): string
    {
        return $this->themeCatalog->root();
    }

    /**
     * Returns the shared public meta service.
     *
     * @return Meta Shared public meta service.
     */
    private function metaService(): Meta
    {
        // Lazily initialize shared public meta service.
        if (!$this->metaService instanceof Meta) {
            $this->metaService = new Meta(
                $this->request,
                $this->themeCatalog,
                $this->profileParser(),
                $this->feedParser
            );
        }

        return $this->metaService;
    }

    /**
     * Returns the shared profile-contact helper.
     *
     * @return UserProfileParser Shared profile-contact helper.
     */
    private function profileParser(): UserProfileParser
    {
        // Lazily initialize profile-contact parser.
        if (!$this->profileParser instanceof UserProfileParser) {
            $this->profileParser = new UserProfileParser($this->context->input());
        }

        return $this->profileParser;
    }

    /**
     * Returns the shared public-template decorator.
     *
     * @return TemplateDecorator Shared public-template decorator.
     */
    private function templateDecorator(): TemplateDecorator
    {
        // Lazily initialize template decorator for public view payload shaping.
        if (!$this->templateDecorator instanceof TemplateDecorator) {
            $this->templateDecorator = new TemplateDecorator(
                $this->context->config(),
                $this->context->input(),
                dirname(__DIR__, 4)
            );
        }

        return $this->templateDecorator;
    }

    /**
     * Returns the shared public page Markdown helper.
     *
     * @return PageMarkdown Shared public page Markdown helper.
     */
    private function pageMarkdown(): PageMarkdown
    {
        // Lazily initialize Markdown helper for page block rendering.
        if (!$this->pageMarkdown instanceof PageMarkdown) {
            $this->pageMarkdown = new PageMarkdown();
        }

        return $this->pageMarkdown;
    }

    /**
     * Returns the shared page-block parser.
     *
     * @return PageBlockParser Shared page-block parser.
     */
    private function pageBlockParser(): PageBlockParser
    {
        // Lazily initialize page-block parser.
        if (!$this->pageBlockParser instanceof PageBlockParser) {
            $this->pageBlockParser = new PageBlockParser($this->context->input());
        }

        return $this->pageBlockParser;
    }

    /**
     * Returns the shared public page-block helper.
     *
     * @return PageBlocks Shared public page-block helper.
     */
    private function pageBlocks(): PageBlocks
    {
        // Lazily initialize public page-block helper.
        if (!$this->pageBlocks instanceof PageBlocks) {
            $this->pageBlocks = new PageBlocks(
                dirname(__DIR__, 4),
                $this->pageBlockParser(),
                $this->pageMarkdown(),
                $this->extensionServicesProvider
            );
        }

        return $this->pageBlocks;
    }

    /**
     * Encodes each public path segment without escaping hierarchy separators.
     *
     * @param string $path Slash-separated public path.
     * @return string Encoded path without a leading slash.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

}
