<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/TagController.php
 * Split public tag controller for tag routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\TagRead;
use Raven\Core\Router\ChannelPolicy;
use Raven\Core\Router\FeedPolicy;
use Raven\Core\Router\PagePolicy;
use Raven\Core\Router\TagPolicy;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Transport\Request;
use Raven\Lib\View\Public\Meta;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Handles split public tag routes.
 */
final class TagController
{
    private SharedController $context;
    private PageRead $pageRead;
    private TagRead $tagRead;
    private Request $request;
    private FeedPolicy $feedParser;
    private TemplateDecorator $templateDecorator;
    private ThemeCatalog $themeCatalog;
    private Meta $metaService;
    private ?ThemeTemplate $themeTemplate = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param PageRead $pageRead Page repository read side for public tag page lists.
     * @param TagRead $tagRead Tag repository read side for tag resolution.
     * @param ThemeCatalog $themeCatalog Shared public-theme catalog for template resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        PageRead $pageRead,
        TagRead $tagRead,
        ThemeCatalog $themeCatalog
    ) {
        $this->context = $context;
        $this->pageRead = $pageRead;
        $this->tagRead = $tagRead;
        $this->request = new Request();
        $this->feedParser = new FeedPolicy($context->config(), $context->input());
        $this->templateDecorator = new TemplateDecorator(
            $context->config(),
            $context->input(),
            dirname(__DIR__, 4)
        );
        $this->themeCatalog = $themeCatalog;
        $this->metaService = new Meta(
            $this->request,
            $this->themeCatalog,
            new UserProfileParser($context->input()),
            $this->feedParser
        );
    }

    /**
     * Renders tag listing route `/{tag_prefix}/{tag_slug}/{page?}`.
     *
     * @param string $tagSlug Normalized tag slug.
     * @param int $pageNumber Requested page number.
     * @return void
     */
    public function tag(string $tagSlug, int $pageNumber = 1): void
    {
        // Route must remain disabled unless tag URLs are explicitly enabled in config.
        if (!TagPolicy::tagRouteEnabled($this->context->config())) {
            $this->context->notFound();
            return;
        }
        $tagPrefix = TagPolicy::tagRoutePrefix($this->context->config(), $this->context->input());

        $tag = $this->tagRead->findBySlug($tagSlug);
        // Unknown tag slugs map to 404 so route probing does not expose internals.
        if ($tag === null) {
            $this->context->notFound();
            return;
        }

        $perPage = max(1, (int) $this->context->config()->get('tag.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageRead->listPageByTagSlug($tagSlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Keep canonical pagination bounds instead of serving sparse out-of-range pages.
        if ($total > 0 && $pageNumber > $totalPages) {
            $this->context->notFound();
            return;
        }

        $pages = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pages = $this->buildPageUrls($pages);
        $pages = $this->templateDecorator->decoratePageListForTemplate($pages);
        $pagination = $this->templateDecorator->decoratePaginationForTemplate([
            'current' => $pageNumber,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_path' => '/' . $tagPrefix . '/' . rawurlencode($tagSlug),
        ]);
        $tagTemplate = $this->themeTemplate()->resolveTagTemplateNameForThemeChain(
            $tagSlug,
            $this->themesRoot(),
            $this->activeThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl/public'
        );

        $this->context->renderPublic($tagTemplate, [
            // Tag-level cover/preview uploads override default site meta image when present.
            'site' => $this->metaService->siteDataWithTaxonomyMetaImage($tag, $this->context->siteData()),
            'tag' => $tag,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Builds public URL paths for tag listing rows.
     *
     * @param array<int, array<string, mixed>> $pages Public page rows.
     * @return array<int, array<string, mixed>> Public page rows with `url` fields.
     */
    private function buildPageUrls(array $pages): array
    {
        // Normalize each row into a public URL so themes can link without route assembly logic.
        foreach ($pages as $index => $page) {
            // Repository rows should be arrays; skip malformed values defensively.
            if (!is_array($page)) {
                continue;
            }

            $slug = $this->context->input()->slug((string) ($page['slug'] ?? ''));
            $pageId = (int) ($page['id'] ?? 0);
            // Missing slugs cannot produce stable permalinks, so point to the site root.
            if ($slug === null || $slug === '') {
                $pages[$index]['url'] = '/';
                continue;
            }

            $channelSlug = $this->context->input()->slug((string) ($page['channel_slug'] ?? ''));
            // Channelless pages use the global route mode instead of channel-specific rules.
            if ($channelSlug === null || $channelSlug === '') {
                $rootSegment = PagePolicy::buildRouteSegment($this->context->input(), 
                    $slug,
                    $pageId,
                    (string) ($page['created'] ?? ''),
                    ChannelPolicy::globalPageRouteMode($this->context->config()),
                    'inherit',
                    (string) $this->context->config()->get('content.separator', '-')
                );
                $pages[$index]['url'] = '/' . rawurlencode($rootSegment !== '' ? $rootSegment : $slug);
                continue;
            }

            $pages[$index]['url'] = '/'
                . rawurlencode($channelSlug)
                . '/'
                . rawurlencode(
                    PagePolicy::buildRouteSegment($this->context->input(), 
                        $slug,
                        $pageId,
                        (string) ($page['created'] ?? ''),
                        ChannelPolicy::effectiveChannelRouteMode($this->context->config(), (string) ($page['route_mode_effective'] ?? 'inherit')),
                        (string) ($page['route_separator_effective'] ?? 'inherit'),
                        (string) $this->context->config()->get('content.separator', '-')
                    )
                );
        }

        return $pages;
    }

    /**
     * Returns the current active public theme slug.
     *
     * @return string Active public theme slug.
     */
    private function activeThemeSlug(): string
    {
        return $this->themeCatalog->activeSlugFromConfig($this->context->config());
    }

    /**
     * Returns the public themes filesystem root.
     *
     * @return string Absolute public theme root.
     */
    private function themesRoot(): string
    {
        return $this->themeCatalog->root();
    }

    /**
     * Returns the shared public theme-template service.
     *
     * @return ThemeTemplate Shared theme-template service.
     */
    private function themeTemplate(): ThemeTemplate
    {
        // Tag routes may resolve template names repeatedly; cache service per request.
        if (!$this->themeTemplate instanceof ThemeTemplate) {
            $this->themeTemplate = new ThemeTemplate($this->context->input());
        }

        return $this->themeTemplate;
    }
}
