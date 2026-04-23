<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/PageController.php
 * Split public page controller for homepage, page, and embedded-form flows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Closure;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Lib\Extension\Public\EmbeddedFormRuntimeInterface;
use Raven\Lib\Extension\Public\EmbeddedFormRuntimeService;
use Raven\Lib\Extension\Public\EmbeddedShortcodeRuntimeInterface;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\PageDataParser;
use Raven\Lib\Parser\UserDataParser;
use Raven\Lib\Transport\Redirect;
use Raven\Core\Routing\Public\PublicChannelPageRouteService;
use Raven\Lib\View\Public\MetaService;
use Raven\Lib\View\Public\PageBlocks;
use Raven\Lib\View\Public\PageMarkdown;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Handles split public homepage and page-routing routes.
 */
final class PageController
{
    private SharedController $context;
    private ChannelDataParser $channelParser;
    private PageImageRepository $pageImages;
    private PageDataParser $pageParser;
    private RedirectRepository $redirectRepo;
    private UserDataParser $userParser;
    private Closure $extensionServicesProvider;
    /** @var array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> */
    private array $embeddedFormRuntimes = [];
    private bool $embeddedFormRuntimesLoaded = false;
    /** @var array<string, array{label: string, editor: string}>|null */
    private ?array $pageBodyBlockTypeDefinitionsCache = null;
    private ThemeCatalog $themeCatalogService;
    private ?ThemeTemplate $themeTemplate = null;
    private ?MetaService $metaService = null;
    private ?TemplateDecorator $templateDecorator = null;
    private ?PageMarkdown $pageMarkdown = null;
    private ?PageBlockParser $pageBlockParser = null;
    private ?PageBlocks $pageBlocks = null;
    private ExtensionEditorCatalogService $extensionEditorCatalogService;
    private ?EmbeddedFormRuntimeService $embeddedFormRuntimeService = null;
    private ?UserDataParser $profileContactService = null;
    private ?PublicChannelPageRouteService $publicChannelPageRouteService = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param ChannelDataParser $channelParser Channel data parser for public channel-route lookups.
     * @param PageImageRepository $pageImages Page-image repository for gallery rendering and page meta images.
     * @param PageRepository $pageRepo Page repository for homepage, channel, and page lookups.
     * @param RedirectRepository $redirectRepo Redirect repository for public redirect fallbacks.
     * @param UserRepository $userRepo User repository for author profile lookups in page meta.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for template resolution and meta reads.
     * @param ExtensionEditorCatalogService $extensionEditorCatalogService Shared extension editor catalog for public block definitions.
     * @param callable(?string=): array<string, mixed> $extensionServicesProvider Lazy extension-services resolver for shortcode runtimes.
     * @return void
     */
    public function __construct(
        SharedController $context,
        ChannelDataParser $channelParser,
        PageImageRepository $pageImages,
        PageRepository $pageRepo,
        RedirectRepository $redirectRepo,
        UserRepository $userRepo,
        ThemeCatalog $themeCatalogService,
        ExtensionEditorCatalogService $extensionEditorCatalogService,
        callable $extensionServicesProvider
    ) {
        $this->context = $context;
        $this->channelParser = $channelParser;
        $this->pageImages = $pageImages;
        $this->pageParser = new PageDataParser($context->input(), $pageRepo);
        $this->redirectRepo = $redirectRepo;
        $this->userParser = new UserDataParser($context->input(), $userRepo);
        $this->themeCatalogService = $themeCatalogService;
        $this->extensionEditorCatalogService = $extensionEditorCatalogService;
        $this->extensionServicesProvider = Closure::fromCallable($extensionServicesProvider);
    }

    /**
     * Renders the homepage using `home` slug or `index` fallback outside channels.
     *
     * @return void
     */
    public function home(): void
    {
        $page = $this->pageParser->findHomepage();
        if ($page === null) {
            $this->context->notFound();
            return;
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->templateDecorator()->decoratePageForTemplate($page);

        $this->context->renderPublic('home', [
            'site' => $this->siteDataWithPageMeta($page),
            'page' => $page,
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
            $channel = $this->channelParser->findBySlug($channelSlug);
            if ($channel === null) {
                if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                    return;
                }

                $this->context->notFound();
                return;
            }

            $channelRouteMode = ChannelRouteParser::effectiveChannelRouteMode($this->context->config(), (string) ($channel['route_mode'] ?? 'inherit'));
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
            $channelRouteMode = ChannelRouteParser::globalPageRouteMode($this->context->config());
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
            $page = $this->pageParser->findPublicPageById((int) ($lookupTarget['id'] ?? 0), $channelSlug);
        } else {
            $page = $this->pageParser->findPublicPage($lookupSlug, $channelSlug);
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
                Redirect::redirect('/' . rawurlencode($channelSlug) . '/' . rawurlencode($canonicalSegment), 301);
            }
        } elseif ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
            Redirect::redirect('/' . rawurlencode($canonicalSegment), 301);
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->templateDecorator()->decoratePageForTemplate($page);
        $pageTemplate = $this->themeTemplate()->resolvePageTemplateNameForThemeChain(
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
     * Handles one public embedded-form submission request by type + slug.
     *
     * Embedded forms are rendered inside page body content, so their submit
     * path now stays on the same public page controller seam instead of
     * bouncing through a dedicated one-route controller.
     *
     * @param string $type Normalized embedded form type slug.
     * @param string $formSlug Normalized embedded form slug.
     * @return void
     */
    public function submitEmbeddedForm(string $type, string $formSlug): void
    {
        $runtime = $this->embeddedFormRuntimeService()->runtime($type, $this->embeddedFormRuntimes());
        if ($runtime === null) {
            $this->context->notFound();
            return;
        }

        if (!$this->embeddedFormRuntimeService()->isRuntimeEnabled($runtime)) {
            $this->context->notFound();
            return;
        }

        // Content-only runtimes have no submit handler, so reject submit posts.
        if (!$runtime instanceof EmbeddedFormRuntimeInterface) {
            $this->context->notFound();
            return;
        }

        $slug = $this->context->input()->slug($formSlug);
        if ($slug === null) {
            $this->context->notFound();
            return;
        }

        $returnPath = $this->embeddedFormRuntimeService()->sanitizeReturnPath((string) ($_POST['return_path'] ?? '/'));

        try {
            $runtime->submit($slug, $returnPath, fn (): ?string => $this->context->validatePublicCaptcha());
        } catch (\Throwable $exception) {
            error_log(
                'Raven embedded form submit failed for type "'
                . $runtime->type()
                . '": '
                . $exception->getMessage()
            );
            $this->context->notFound();
        }
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

        return $this->metaService()->siteDataWithPageMeta(
            $page,
            $this->context->siteData(),
            fn (int $pageId): ?string => $this->pageImages->coverImageUrlForPage($pageId),
            fn (int $authorUserId): ?array => $this->userParser->findById($authorUserId),
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
            $this->pageBodyBlockTypeDefinitions(),
            fn (): string => $this->renderPageGalleryBlockHtml($page),
            fn (string $html): string => $this->renderEmbeddedForms($html)
        );
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

        $galleryImages = $this->templateDecorator()->decorateGalleryImagesForTemplate(
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
    private function pageBodyBlockTypeDefinitions(): array
    {
        if (is_array($this->pageBodyBlockTypeDefinitionsCache)) {
            return $this->pageBodyBlockTypeDefinitionsCache;
        }

        $this->pageBodyBlockTypeDefinitionsCache = $this->pageBlocks()->mergeTypeDefinitions(
            $this->extensionEditorCatalogService->publicBodyBlockDefinitions()
        );

        return $this->pageBodyBlockTypeDefinitionsCache;
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
        Redirect::redirect($targetUrl, 302);
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
     * Returns the shared public theme-template service.
     *
     * @return ThemeTemplate Shared theme-template service.
     */
    private function themeTemplate(): ThemeTemplate
    {
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
    private function currentPublicThemeSlug(): string
    {
        return $this->themeCatalogService->activeSlugFromConfig($this->context->config());
    }

    /**
     * Returns the filesystem root containing public themes.
     *
     * @return string Absolute public theme root.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService->root();
    }

    /**
     * Returns the shared public meta service.
     *
     * @return MetaService Shared public meta service.
     */
    private function metaService(): MetaService
    {
        if (!$this->metaService instanceof MetaService) {
            $this->metaService = new MetaService(
                $this->context->requestContextResolver(),
                $this->themeCatalogService,
                $this->profileContactService(),
                $this->context->feedParser()
            );
        }

        return $this->metaService;
    }

    /**
     * Returns the shared profile-contact helper.
     *
     * @return UserDataParser Shared profile-contact helper.
     */
    private function profileContactService(): UserDataParser
    {
        if (!$this->profileContactService instanceof UserDataParser) {
            $this->profileContactService = new UserDataParser($this->context->input());
        }

        return $this->profileContactService;
    }

    /**
     * Returns the shared public-template decorator.
     *
     * @return TemplateDecorator Shared public-template decorator.
     */
    private function templateDecorator(): TemplateDecorator
    {
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
        if (!$this->pageBlocks instanceof PageBlocks) {
            $this->pageBlocks = new PageBlocks(
                dirname(__DIR__, 4),
                $this->pageBlockParser(),
                $this->pageMarkdown()
            );
        }

        return $this->pageBlocks;
    }

}
