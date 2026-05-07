<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/CategoryController.php
 * Split public category controller for category routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\PageRead;
use Raven\Lib\Parser\CategoryRouteParser;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\FeedParser;
use Raven\Lib\Parser\PageRouteParser;
use Raven\Lib\Parser\UserProfileParser;
use Raven\Lib\Transport\Request;
use Raven\Lib\View\Public\Meta;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Handles split public category routes.
 */
final class CategoryController
{
    private SharedController $context;
    private PageRead $pageRead;
    private CategoryRead $categoryRead;
    private Request $request;
    private FeedParser $feedParser;
    private TemplateDecorator $templateDecorator;
    private ThemeCatalog $themeCatalog;
    private Meta $metaService;
    private ?ThemeTemplate $themeTemplate = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param PageRead $pageRead Page repository read side for public category page lists.
     * @param CategoryRead $categoryRead Category repository read side for category resolution.
     * @param ThemeCatalog $themeCatalog Shared public-theme catalog for template resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        PageRead $pageRead,
        CategoryRead $categoryRead,
        ThemeCatalog $themeCatalog
    ) {
        $this->context = $context;
        $this->pageRead = $pageRead;
        $this->categoryRead = $categoryRead;
        $this->request = new Request();
        $this->feedParser = new FeedParser($context->config(), $context->input());
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
     * Renders category listing route `/{category_prefix}/{category_slug}/{page?}`.
     *
     * @param string $categorySlug Normalized category slug.
     * @param int $pageNumber Requested page number.
     * @return void
     */
    public function category(string $categorySlug, int $pageNumber = 1): void
    {
        $categoryPrefix = CategoryRouteParser::categoryRoutePrefix($this->context->config(), $this->context->input());
        if ($categoryPrefix === '') {
            $this->context->notFound();
            return;
        }

        $category = $this->categoryRead->findBySlug($categorySlug);
        if ($category === null) {
            $this->context->notFound();
            return;
        }

        $perPage = max(1, (int) $this->context->config()->get('category.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageRead->listPageByCategorySlug($categorySlug, $perPage, $offset);
        $total = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));

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
            'base_path' => '/' . $categoryPrefix . '/' . rawurlencode($categorySlug),
        ]);
        $categoryTemplate = $this->themeTemplate()->resolveCategoryTemplateNameForThemeChain(
            $categorySlug,
            $this->themesRoot(),
            $this->activeThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl/public'
        );

        $this->context->renderPublic($categoryTemplate, [
            // Category-level cover/preview uploads override default site meta image when present.
            'site' => $this->metaService->siteDataWithTaxonomyMetaImage($category, $this->context->siteData()),
            'category' => $category,
            'pages' => $pages,
            'pagination' => $pagination,
        ], 'wrapper');
    }

    /**
     * Builds public URL paths for category listing rows.
     *
     * @param array<int, array<string, mixed>> $pages Public page rows.
     * @return array<int, array<string, mixed>> Public page rows with `url` fields.
     */
    private function buildPageUrls(array $pages): array
    {
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $slug = $this->context->input()->slug((string) ($page['slug'] ?? ''));
            $pageId = (int) ($page['id'] ?? 0);
            if ($slug === null || $slug === '') {
                $pages[$index]['url'] = '/';
                continue;
            }

            $channelSlug = $this->context->input()->slug((string) ($page['channel_slug'] ?? ''));
            if ($channelSlug === null || $channelSlug === '') {
                $rootSegment = PageRouteParser::buildRouteSegment($this->context->input(), 
                    $slug,
                    $pageId,
                    (string) ($page['created'] ?? ''),
                    ChannelRouteParser::globalPageRouteMode($this->context->config()),
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
                    PageRouteParser::buildRouteSegment($this->context->input(), 
                        $slug,
                        $pageId,
                        (string) ($page['created'] ?? ''),
                        ChannelRouteParser::effectiveChannelRouteMode($this->context->config(), (string) ($page['route_mode_effective'] ?? 'inherit')),
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
        if (!$this->themeTemplate instanceof ThemeTemplate) {
            $this->themeTemplate = new ThemeTemplate($this->context->input());
        }

        return $this->themeTemplate;
    }
}
