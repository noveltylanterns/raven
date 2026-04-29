<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/CategoryController.php
 * Split public category controller for category routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\PageRead;
use Raven\Core\Routing\Public\ChannelPageRouter;
use Raven\Lib\Parser\CategoryRepoParser;
use Raven\Lib\Parser\ChannelRouteParser;
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
    private CategoryRepoParser $categoryLookupRepo;
    private TemplateDecorator $templateDecorator;
    private ChannelPageRouter $publicChannelPageRouteService;
    private ThemeCatalog $themeCatalogService;
    private ?ThemeTemplate $themeTemplate = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param PageRead $pageRepo Page repository read side for public category page lists.
     * @param CategoryRepoParser $categoryLookupRepo Category lookup parser for category resolution.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for template resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        PageRead $pageRepo,
        CategoryRepoParser $categoryLookupRepo,
        ThemeCatalog $themeCatalogService
    ) {
        $this->context = $context;
        $this->pageRead = $pageRepo;
        $this->categoryLookupRepo = $categoryLookupRepo;
        $this->templateDecorator = new TemplateDecorator(
            $context->config(),
            $context->input(),
            dirname(__DIR__, 4)
        );
        $this->publicChannelPageRouteService = new ChannelPageRouter($context->input());
        $this->themeCatalogService = $themeCatalogService;
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
        $categoryPrefix = $this->context->categoryParser()->categoryRoutePrefix();
        if ($categoryPrefix === '') {
            $this->context->notFound();
            return;
        }

        $category = $this->categoryLookupRepo->findBySlug($categorySlug);
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
        $pages = $this->decoratePageListPublicPaths($pages);
        $pages = $this->templateDecorator->decoratePageListForTemplate($pages);
        $pagination = $this->templateDecorator->decoratePaginationForTemplate([
            'current' => $pageNumber,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_path' => '/' . $categoryPrefix . '/' . rawurlencode($categorySlug),
        ]);
        $categoryTemplate = $this->themeTemplate()->resolveCategoryTemplateNameForThemeChain(
            $categorySlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        $this->context->renderPublic($categoryTemplate, [
            'site' => $this->context->siteDataWithTaxonomyMetaImage($category),
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
    private function decoratePageListPublicPaths(array $pages): array
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
                $rootSegment = $this->publicChannelPageRouteService->canonicalSegment(
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
                    $this->publicChannelPageRouteService->canonicalSegment(
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
    private function currentPublicThemeSlug(): string
    {
        return $this->themeCatalogService->activeSlugFromConfig($this->context->config());
    }

    /**
     * Returns the public themes filesystem root.
     *
     * @return string Absolute public theme root.
     */
    private function publicThemesRoot(): string
    {
        return $this->themeCatalogService->root();
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
