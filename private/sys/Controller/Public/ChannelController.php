<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/ChannelController.php
 * Split public channel controller for single-segment channel routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Closure;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\UserRead;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Extension\Public\EmbeddedFormRuntimeInterface;
use Raven\Lib\Extension\Public\EmbeddedFormRuntimeService;
use Raven\Lib\Extension\Public\EmbeddedShortcodeRuntimeInterface;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\PageRouteParser;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\View\Public\MetaService;
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
    private MediaRead $media;
    private PageRead $pageRead;
    private RedirectRead $redirectRead;
    private UserRead $userRead;
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
    private ?UserProfileParser $profileContactService = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param MediaRead $media Media repository read side for gallery rendering and page meta images.
     * @param PageRead $pageRead Page repository read side for channel-homepage and root-page lookups.
     * @param RedirectRead $redirectRead Redirect repository read side for public redirect fallbacks.
     * @param UserRead $userRead User repository read side for author profile lookups in page meta.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for template resolution and meta reads.
     * @param ExtensionEditorCatalogService $extensionEditorCatalogService Shared extension editor catalog for public block definitions.
     * @param callable(?string=): array<string, mixed> $extensionServicesProvider Lazy extension-services resolver for shortcode runtimes.
     * @return void
     */
    public function __construct(
        SharedController $context,
        MediaRead $media,
        PageRead $pageRead,
        RedirectRead $redirectRead,
        UserRead $userRead,
        ThemeCatalog $themeCatalogService,
        ExtensionEditorCatalogService $extensionEditorCatalogService,
        callable $extensionServicesProvider
    ) {
        $this->context = $context;
        $this->media = $media;
        $this->pageRead = $pageRead;
        $this->redirectRead = $redirectRead;
        $this->userRead = $userRead;
        $this->themeCatalogService = $themeCatalogService;
        $this->extensionEditorCatalogService = $extensionEditorCatalogService;
        $this->extensionServicesProvider = Closure::fromCallable($extensionServicesProvider);
    }

    /**
     * Resolves one single-segment public route by slug.
     *
     * Channel landing pages keep priority on this route shape. When a channel
     * does not exist or has no landing page, the same slug is reinterpreted as
     * a root-scope page route before falling through to redirects and 404s.
     *
     * @param string $channelSlug Normalized single-segment slug.
     * @return void
     */
    public function channel(string $channelSlug): void
    {
        $requestedSlug = strtolower(trim($channelSlug));
        if ($requestedSlug === '') {
            $this->context->notFound();
            return;
        }

        // findChannelHomepage() returns null when the channel does not exist, or a
        // ['channel' => ..., 'page' => ...] tuple — page is null when no homepage exists.
        $result = $this->pageRead->findChannelHomepage($requestedSlug);
        if (is_array($result) && is_array($result['page'] ?? null)) {
            $channel = is_array($result['channel'] ?? null) ? $result['channel'] : [];
            $page = $this->renderPageContentBlocks($result['page']);
            $page = $this->templateDecorator()->decoratePageForTemplate($page);

            $channelTemplate = $this->themeTemplate()->resolveChannelTemplateNameForThemeChain(
                $requestedSlug,
                $this->publicThemesRoot(),
                $this->currentPublicThemeSlug(),
                dirname(__DIR__, 4) . '/private/tpl'
            );

            $site = $this->siteDataWithPageMeta($page);
            // Channel-level cover/preview uploads override page/default meta images on channel landings.
            $site = $this->context->siteDataWithTaxonomyMetaImage($channel, $site);

            $this->context->renderPublic($channelTemplate, [
                'site' => $site,
                'channel' => $channel,
                'page' => $page,
            ], 'wrapper');
            return;
        }

        $channelRouteMode = ChannelRouteParser::globalPageRouteMode($this->context->config());
        $lookupTarget = PageRouteParser::resolveLookupTarget($this->context->input(), 
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

        $page = null;
        if ((string) ($lookupTarget['type'] ?? '') === 'id') {
            $page = $this->pageRead->findPublishedById((int) ($lookupTarget['id'] ?? 0), null);
        } else {
            $lookupSlug = (string) ($lookupTarget['slug'] ?? $requestedSlug);
            $page = $this->pageRead->findPublishedBySlug($lookupSlug, null);
        }

        if ($page === null) {
            if ($this->tryRedirect($requestedSlug, null)) {
                return;
            }

            $this->context->notFound();
            return;
        }

        $canonicalSegment = PageRouteParser::buildRouteSegment($this->context->input(), 
            (string) ($page['slug'] ?? ''),
            (int) ($page['id'] ?? 0),
            (string) ($page['created'] ?? ''),
            $channelRouteMode,
            'inherit',
            (string) $this->context->config()->get('content.separator', '-')
        );
        if ($canonicalSegment !== '' && strcasecmp($canonicalSegment, $requestedSlug) !== 0) {
            Redirect::redirect('/' . rawurlencode($canonicalSegment), 301);
        }

        $page = $this->renderPageContentBlocks($page);
        $page = $this->templateDecorator()->decoratePageForTemplate($page);
        $pageTemplate = $this->themeTemplate()->resolvePageTemplateNameForThemeChain(
            null,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
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
            $this->pageBodyBlockTypeDefinitions(),
            fn (): string => $this->renderPageGalleryBlockHtml($page),
            fn (string $html): string => $this->renderEmbeddedForms($html)
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
        if ($pageId <= 0) {
            return '';
        }

        $galleryImages = $this->templateDecorator()->decorateGalleryImagesForTemplate(
            $this->media->listDisplayReadyForPage($pageId)
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
        $redirectRow = $this->redirectRead->findActiveByPath($pageSlug, $channelSlug);
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
     * Returns the shared public theme-template service.
     *
     * @return ThemeTemplate Shared public theme-template service.
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
     * @return UserProfileParser Shared profile-contact helper.
     */
    private function profileContactService(): UserProfileParser
    {
        if (!$this->profileContactService instanceof UserProfileParser) {
            $this->profileContactService = new UserProfileParser($this->context->input());
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
