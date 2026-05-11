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
 * Handles split public homepage and page-routing routes.
 */
final class PageController
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
     * @param ChannelRead $channelRead Channel repository read side for public channel-route lookups.
     * @param MediaRead $media Media repository read side for gallery rendering and page meta images.
     * @param PageRead $pageRead Page repository read side for homepage, channel, and page lookups.
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
     * Renders the homepage using `home` slug or `index` fallback outside channels.
     *
     * @return void
     */
    public function home(): void
    {
        $page = $this->pageRead->findHomepage();
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
            $channel = $this->channelRead->findBySlug($channelSlug);
            if ($channel === null) {
                if ($this->tryRedirect($requestedSlug, $channelSlug)) {
                    return;
                }

                $this->context->notFound();
                return;
            }

            $channelRouteMode = ChannelPolicy::effectiveChannelRouteMode($this->context->config(), (string) ($channel['route_mode'] ?? 'inherit'));
            $channelWordSeparator = ChannelPolicy::resolveSeparator(
                (string) ($channel['route_separator'] ?? 'inherit'),
                (string) $this->context->config()->get('content.separator', '-')
            );

            $lookupTarget = PagePolicy::resolveLookupTarget($this->context->input(), 
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
            $channelRouteMode = ChannelPolicy::globalPageRouteMode($this->context->config());
            $lookupTarget = PagePolicy::resolveLookupTarget($this->context->input(), 
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
            $page = $this->pageRead->findPublishedById((int) ($lookupTarget['id'] ?? 0), $channelSlug);
        } else {
            $page = $this->pageRead->findPublishedBySlug($lookupSlug, $channelSlug);
        }

        if ($page === null) {
            // Redirect fallback stays ahead of 404 so legacy slugs still resolve cleanly.
            if ($this->tryRedirect($requestedSlug, $channelSlug)) {
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
            $this->themesRoot(),
            $this->activeThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl/public'
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
        $runtime = $this->formInstance()->runtime($type, $this->shortcodeRuntimes());
        if ($runtime === null) {
            $this->context->notFound();
            return;
        }

        if (!$this->formInstance()->isRuntimeEnabled($runtime)) {
            $this->context->notFound();
            return;
        }

        // Content-only runtimes have no submit handler, so reject submit posts.
        if (!$runtime instanceof ExtensionFormRuntime) {
            $this->context->notFound();
            return;
        }

        $slug = $this->context->input()->slug($formSlug);
        if ($slug === null) {
            $this->context->notFound();
            return;
        }

        $returnPath = $this->formInstance()->sanitizeReturnPath((string) ($_POST['return_path'] ?? '/'));

        try {
            $runtime->submit($slug, $returnPath, fn (): ?string => $this->publicCaptchaFlow->validateSubmission($_POST, $_SERVER));
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
        $profileContactOptions = $this->profileParser()->normalizeOptionsConfig(
            $this->context->config()->get('user.contact', $this->profileParser()->defaultOptions())
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
    private function blockTypeDefinitions(): array
    {
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
        if ($redirectRow === null) {
            return false;
        }

        $targetUrl = trim((string) ($redirectRow['target'] ?? ''));
        if (!RedirectParser::isAllowedHttpOrRootPath($targetUrl)) {
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
    private function activeThemeSlug(): string
    {
        return $this->themeCatalog->activeSlugFromConfig($this->context->config());
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
