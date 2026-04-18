<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/ContentController.php
 * Split public content controller for homepage and page-routing flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Closure;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Lib\Content\BodyBlockPolicy;
use Raven\Lib\Content\MarkdownRenderer;
use Raven\Lib\Content\PublicPageBodyRenderer;
use Raven\Lib\Extension\Public\EmbeddedFormRuntimeInterface;
use Raven\Lib\Extension\Public\EmbeddedFormRuntimeService;
use Raven\Lib\Extension\Public\EmbeddedShortcodeRuntimeInterface;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\Profile\ProfileContactService;
use Raven\Lib\Routing\Public\PublicChannelPageRouteService;
use Raven\Lib\Site\PublicMetaService;
use Raven\Lib\Site\SiteContextBuilder;
use Raven\Lib\View\Public\PublicTemplateDecorator;
use Raven\Lib\View\Public\PublicTemplatePipeline;
use Raven\Lib\View\Public\PublicTemplateResolver;
use Raven\Lib\View\Panel\ThemeCatalogService;

use function Raven\Lib\Support\redirect;

/**
 * Handles split public homepage and page-routing routes.
 */
final class ContentController
{
    private RequestContext $context;
    private ChannelRepository $channelRepo;
    private PageImageRepository $pageImages;
    private PageRepository $pageRepo;
    private RedirectRepository $redirectRepo;
    private UserRepository $userRepo;
    private Closure $extensionServicesProvider;
    /** @var array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> */
    private array $embeddedFormRuntimes = [];
    private bool $embeddedFormRuntimesLoaded = false;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;
    private ?ThemeCatalogService $themeCatalogService = null;
    private ?PublicTemplateResolver $publicTemplateResolver = null;
    private ?PublicTemplatePipeline $publicTemplatePipeline = null;
    private ?PublicMetaService $publicMetaService = null;
    private ?PublicTemplateDecorator $publicTemplateDecorator = null;
    private ?PublicPageBodyRenderer $pageBodyRenderer = null;
    private ?MarkdownRenderer $markdownRenderer = null;
    private ?BodyBlockPolicy $bodyBlockPolicy = null;
    private ?ExtensionEditorCatalogService $extensionEditorCatalogService = null;
    private ?EmbeddedFormRuntimeService $embeddedFormRuntimeService = null;
    private ?ProfileContactService $profileContactService = null;
    private ?PublicChannelPageRouteService $publicChannelPageRouteService = null;

    /**
     * @param RequestContext $context Shared public request context.
     * @param ChannelRepository $channelRepo Channel repository for public channel-route lookups.
     * @param PageImageRepository $pageImages Page-image repository for gallery rendering and page meta images.
     * @param PageRepository $pageRepo Page repository for homepage, channel, and page lookups.
     * @param RedirectRepository $redirectRepo Redirect repository for public redirect fallbacks.
     * @param UserRepository $userRepo User repository for author profile lookups in page meta.
     * @param callable(?string=): array<string, mixed> $extensionServicesProvider Lazy extension-services resolver for shortcode runtimes.
     * @return void
     */
    public function __construct(
        RequestContext $context,
        ChannelRepository $channelRepo,
        PageImageRepository $pageImages,
        PageRepository $pageRepo,
        RedirectRepository $redirectRepo,
        UserRepository $userRepo,
        callable $extensionServicesProvider
    ) {
        $this->context = $context;
        $this->channelRepo = $channelRepo;
        $this->pageImages = $pageImages;
        $this->pageRepo = $pageRepo;
        $this->redirectRepo = $redirectRepo;
        $this->userRepo = $userRepo;
        $this->extensionServicesProvider = Closure::fromCallable($extensionServicesProvider);
    }

    /**
     * Renders the homepage using `home` slug or `index` fallback outside channels.
     *
     * @return void
     */
    public function home(): void
    {
        $page = $this->pageRepo->findHomepage();
        if ($page === null) {
            $this->context->notFound();
            return;
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->publicTemplateDecorator()->decoratePageForTemplate($page);

        $this->context->renderPublic('home', [
            'site' => $this->siteDataWithPageMeta($page),
            'page' => $page,
        ], 'wrapper');
    }

    /**
     * Resolves one channel landing route by channel slug.
     *
     * Unpacks the tuple returned by `findChannelHomepage()` to reuse the already-fetched
     * channel row, avoiding a second DB round-trip for the same row on every channel landing.
     *
     * @param string $channelSlug Normalized channel slug.
     * @return void
     */
    public function channel(string $channelSlug): void
    {
        // findChannelHomepage() returns null when the channel does not exist, or a
        // ['channel' => ..., 'page' => ...] tuple — page is null when no homepage exists.
        $result = $this->pageRepo->findChannelHomepage($channelSlug);
        if ($result === null || $result['page'] === null) {
            $this->page($channelSlug, null);
            return;
        }

        $channel = $result['channel'];
        $page    = $result['page'];
        $page = $this->renderPageContentBlocks($page);
        $page = $this->publicTemplateDecorator()->decoratePageForTemplate($page);

        $channelTemplate = $this->publicTemplatePipeline()->resolveChannelTemplateNameForThemeChain(
            $channelSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        $site = $this->siteDataWithPageMeta($page);
        // Channel-level cover/preview uploads override page/default meta images on channel landings.
        $site = $this->context->siteDataWithTaxonomyMetaImage($channel, $site);

        $this->context->renderPublic($channelTemplate, [
            'site'    => $site,
            'channel' => $channel,
            'page'    => $page,
        ], 'wrapper');
    }

    /**
     * Renders one public page, optionally nested by channel slug.
     *
     * @param string $pageSlug Raw requested page slug segment.
     * @param string|null $channelSlug Optional parent channel slug.
     * @return void
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
            $channel = $this->channelRepo->findBySlug($channelSlug);
            if ($channel === null) {
                if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                    return;
                }

                $this->context->notFound();
                return;
            }

            $channelRouteMode = $this->context->routeConfigService()->effectiveChannelRouteMode((string) ($channel['route_mode'] ?? 'inherit'));
            $channelWordSeparator = $this->publicChannelPageRouteService()->resolveWordSeparator(
                (string) ($channel['route_separator'] ?? 'inherit'),
                (string) $this->context->config()->get('content.separator', '-')
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

                $this->context->notFound();
                return;
            }

            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        } else {
            $channelRouteMode = $this->context->routeConfigService()->globalPageRouteMode();
            $lookupTarget = $this->publicChannelPageRouteService()->resolveLookupTarget(
                $requestedSlug,
                $channelRouteMode,
                (string) $this->context->config()->get('content.separator', '-')
            );
            if (!is_array($lookupTarget)) {
                if ($this->tryRedirect($requestedSlug, null)) {
                    return;
                }

                $this->context->notFound();
                return;
            }

            if ((string) ($lookupTarget['type'] ?? '') === 'slug') {
                $lookupSlug = (string) ($lookupTarget['slug'] ?? '');
            }
        }

        $page = null;
        if (is_array($lookupTarget) && (string) ($lookupTarget['type'] ?? '') === 'id') {
            $page = $this->pageRepo->findPublicPageById((int) ($lookupTarget['id'] ?? 0), $channelSlug);
        } else {
            $page = $this->pageRepo->findPublicPage($lookupSlug, $channelSlug);
        }

        if ($page === null) {
            // Redirect fallback stays ahead of 404 so legacy slugs still resolve cleanly.
            if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                return;
            }

            $this->context->notFound();
            return;
        }

        $canonicalSegment = $this->publicChannelPageRouteService()->canonicalSegment(
            (string) ($page['slug'] ?? ''),
            (int) ($page['id'] ?? 0),
            (string) ($page['created'] ?? ''),
            $channelRouteMode,
            $channelWordSeparator,
            (string) $this->context->config()->get('content.separator', '-')
        );
        if ($channelSlug !== null) {
            if ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
                redirect('/' . rawurlencode($channelSlug) . '/' . rawurlencode($canonicalSegment), 301);
            }
        } elseif ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
            redirect('/' . rawurlencode($canonicalSegment), 301);
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->publicTemplateDecorator()->decoratePageForTemplate($page);
        $pageTemplate = $this->publicTemplatePipeline()->resolvePageTemplateNameForThemeChain(
            $channelSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        $this->context->renderPublic($pageTemplate, [
            'site' => $this->siteDataWithPageMeta($page),
            'channel' => is_array($channel) ? $channel : null,
            'page' => $page,
        ], 'wrapper');
    }

    /**
     * Returns site data with page-level social metadata overrides when available.
     *
     * @param array<string, mixed> $page Public page payload.
     * @return array<string, mixed> Site metadata payload with page overrides.
     */
    private function siteDataWithPageMeta(array $page): array
    {
        $profileContactOptions = $this->profileContactService()->normalizeOptionsConfig(
            $this->context->config()->get('user.contact', $this->profileContactService()->defaultOptions())
        );

        return $this->publicMetaService()->siteDataWithPageMeta(
            $page,
            $this->context->siteData(),
            fn (int $pageId): ?string => $this->pageImages->coverImageUrlForPage($pageId),
            fn (int $authorUserId): ?array => $this->userRepo->findById($authorUserId),
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
                $cssId = $this->bodyBlockPolicy()->normalizeCssId($block['css_id'] ?? null);
                $cssClass = $this->bodyBlockPolicy()->normalizeCssClassList($block['css_class'] ?? null);
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
     * @param array<string, mixed> $page Public page payload.
     * @return string Gallery block HTML.
     */
    private function renderPageGalleryBlockHtml(array $page): string
    {
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId <= 0) {
            return '';
        }

        $galleryImages = $this->publicTemplateDecorator()->decorateGalleryImagesForTemplate(
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

    /**
     * Escapes HTML output for gallery block fragments.
     *
     * @param string $value Raw output value.
     * @return string Escaped output value.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Renders one public page body block into HTML.
     *
     * @param string $type Normalized body-block type key.
     * @param string $content Raw stored body-block content.
     * @return string Rendered block HTML.
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
     * Normalizes one page body-block type value.
     *
     * @param string $value Raw block type.
     * @return string Normalized block type.
     */
    private function normalizePageBodyBlockType(string $value): string
    {
        return $this->bodyBlockPolicy()->normalizeType($value, $this->pageBodyBlockTypeDefinitions());
    }

    /**
     * Resolves the editor mode for one public page body-block type.
     *
     * @param string $type Normalized block type.
     * @return string Editor mode key.
     */
    private function pageBodyBlockEditorMode(string $type): string
    {
        return $this->bodyBlockPolicy()->editorMode($type, $this->pageBodyBlockTypeDefinitions());
    }

    /**
     * Returns public page body-block type definitions.
     *
     * @return array<string, array{label: string, editor: string}> Body-block definitions keyed by type.
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
     * Attempts active redirect lookup for a URL path and emits HTTP redirect when found.
     *
     * @param string $pageSlug Requested page slug segment.
     * @param string|null $channelSlug Optional channel slug.
     * @return bool True when a redirect response was emitted.
     */
    private function tryRedirect(string $pageSlug, ?string $channelSlug = null): bool
    {
        $redirectRow = $this->redirectRepo->findActiveByPath($pageSlug, $channelSlug);
        if ($redirectRow === null) {
            return false;
        }

        $targetUrl = trim((string) ($redirectRow['target'] ?? ''));
        if (!Redirect::isAllowedHttpOrRootPath($targetUrl)) {
            return false;
        }

        // Default behavior remains temporary until route status configuration is introduced.
        redirect($targetUrl, 302);
        return true;
    }

    /**
     * Resolves supported form shortcodes inside editor HTML content.
     *
     * @param string $html Raw HTML containing potential shortcode markers.
     * @return string HTML with supported shortcodes expanded.
     */
    private function renderEmbeddedForms(string $html): string
    {
        return $this->embeddedFormRuntimeService()->renderShortcodesForPublicRoute(
            $html,
            $this->embeddedFormRuntimes(),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $this->context->csrfField(),
            fn (): string => $this->context->publicCaptchaMarkup()
        );
    }

    /**
     * Returns the embedded-form runtime service for the current request.
     *
     * @return EmbeddedFormRuntimeService Shared embedded-form runtime service.
     */
    private function embeddedFormRuntimeService(): EmbeddedFormRuntimeService
    {
        if (!$this->embeddedFormRuntimeService instanceof EmbeddedFormRuntimeService) {
            $this->embeddedFormRuntimeService = new EmbeddedFormRuntimeService(
                $this->context->input(),
                dirname(__DIR__, 4)
            );
        }

        return $this->embeddedFormRuntimeService;
    }

    /**
     * Returns the discovered embedded shortcode/form runtimes for the current request.
     *
     * @return array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> Runtime map keyed by shortcode type.
     */
    private function embeddedFormRuntimes(): array
    {
        if (!$this->embeddedFormRuntimesLoaded) {
            $this->embeddedFormRuntimes = $this->embeddedFormRuntimeService()->discoverRuntimes($this->extensionServices());
            $this->embeddedFormRuntimesLoaded = true;
        }

        return $this->embeddedFormRuntimes;
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
     * Returns the shared public channel/page routing helper.
     *
     * @return PublicChannelPageRouteService Shared public channel/page routing helper.
     */
    private function publicChannelPageRouteService(): PublicChannelPageRouteService
    {
        if (!$this->publicChannelPageRouteService instanceof PublicChannelPageRouteService) {
            $this->publicChannelPageRouteService = new PublicChannelPageRouteService($this->context->input());
        }

        return $this->publicChannelPageRouteService;
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
     * Returns the active public theme slug.
     *
     * @return string Active public theme slug.
     */
    private function currentPublicThemeSlug(): string
    {
        return $this->themeCatalogService()->activeSlugFromConfig($this->context->config());
    }

    /**
     * Returns the filesystem root containing public themes.
     *
     * @return string Absolute public theme root.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService()->root();
    }

    /**
     * Returns the shared public theme catalog service.
     *
     * @return ThemeCatalogService Shared public theme catalog service.
     */
    private function themeCatalogService(): ThemeCatalogService
    {
        if (!$this->themeCatalogService instanceof ThemeCatalogService) {
            $this->themeCatalogService = new ThemeCatalogService(
                dirname(__DIR__, 4) . '/public/theme',
                $this->context->input(),
                ['raven']
            );
        }

        return $this->themeCatalogService;
    }

    /**
     * Returns the shared public-meta service.
     *
     * @return PublicMetaService Shared public-meta service.
     */
    private function publicMetaService(): PublicMetaService
    {
        if (!$this->publicMetaService instanceof PublicMetaService) {
            $this->publicMetaService = new PublicMetaService(
                $this->context->requestContextResolver(),
                new SiteContextBuilder(),
                $this->themeCatalogService(),
                $this->profileContactService(),
                $this->context->routeConfigService()
            );
        }

        return $this->publicMetaService;
    }

    /**
     * Returns the shared profile-contact helper.
     *
     * @return ProfileContactService Shared profile-contact helper.
     */
    private function profileContactService(): ProfileContactService
    {
        if (!$this->profileContactService instanceof ProfileContactService) {
            $this->profileContactService = new ProfileContactService($this->context->input());
        }

        return $this->profileContactService;
    }

    /**
     * Returns the shared public template decorator.
     *
     * @return PublicTemplateDecorator Shared public template decorator.
     */
    private function publicTemplateDecorator(): PublicTemplateDecorator
    {
        if (!$this->publicTemplateDecorator instanceof PublicTemplateDecorator) {
            $this->publicTemplateDecorator = new PublicTemplateDecorator(
                $this->context->config(),
                $this->context->input(),
                dirname(__DIR__, 4)
            );
        }

        return $this->publicTemplateDecorator;
    }

    /**
     * Returns the shared public page-body renderer.
     *
     * @return PublicPageBodyRenderer Shared public page-body renderer.
     */
    private function pageBodyRenderer(): PublicPageBodyRenderer
    {
        if (!$this->pageBodyRenderer instanceof PublicPageBodyRenderer) {
            $this->pageBodyRenderer = new PublicPageBodyRenderer(
                dirname(__DIR__, 4),
                $this->markdownRenderer()
            );
        }

        return $this->pageBodyRenderer;
    }

    /**
     * Returns the shared Markdown renderer.
     *
     * @return MarkdownRenderer Shared Markdown renderer.
     */
    private function markdownRenderer(): MarkdownRenderer
    {
        if (!$this->markdownRenderer instanceof MarkdownRenderer) {
            $this->markdownRenderer = new MarkdownRenderer();
        }

        return $this->markdownRenderer;
    }

    /**
     * Returns the shared body-block policy.
     *
     * @return BodyBlockPolicy Shared body-block policy.
     */
    private function bodyBlockPolicy(): BodyBlockPolicy
    {
        if (!$this->bodyBlockPolicy instanceof BodyBlockPolicy) {
            $this->bodyBlockPolicy = new BodyBlockPolicy($this->context->input());
        }

        return $this->bodyBlockPolicy;
    }

    /**
     * Returns the shared extension editor catalog service.
     *
     * @return ExtensionEditorCatalogService Shared extension editor catalog service.
     */
    private function extensionEditorCatalogService(): ExtensionEditorCatalogService
    {
        if (!$this->extensionEditorCatalogService instanceof ExtensionEditorCatalogService) {
            $this->extensionEditorCatalogService = new ExtensionEditorCatalogService(
                dirname(__DIR__, 4),
                $this->context->input(),
                $this->bodyBlockPolicy()
            );
        }

        return $this->extensionEditorCatalogService;
    }
}
