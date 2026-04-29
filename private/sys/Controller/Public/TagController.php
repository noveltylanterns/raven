<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/TagController.php
 * Split public tag controller for tag routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Raven\Core\Repository\PageRead;
use Raven\Core\Routing\Public\ChannelPageRouter;
use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\PageDataParser;
use Raven\Lib\Parser\TagRepoParser;
use Raven\Lib\View\Public\TemplateDecorator;
use Raven\Lib\View\Public\ThemeCatalog;
use Raven\Lib\View\Public\ThemeTemplate;

/**
 * Handles split public tag routes.
 */
final class TagController
{
    private SharedController $context;
    private PageDataParser $pageParser;
    private TagRepoParser $tagLookupRepo;
    private TemplateDecorator $templateDecorator;
    private ChannelPageRouter $publicChannelPageRouteService;
    private ThemeCatalog $themeCatalogService;
    private ?ThemeTemplate $themeTemplate = null;

    /**
     * @param SharedController $context Shared public request context.
     * @param PageRead $pageRepo Page repository read side for public tag page lists.
     * @param TagRepoParser $tagLookupRepo Tag lookup parser for tag resolution.
     * @param ThemeCatalog $themeCatalogService Shared public-theme catalog for template resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        PageRead $pageRepo,
        TagRepoParser $tagLookupRepo,
        ThemeCatalog $themeCatalogService
    ) {
        $this->context = $context;
        $this->pageParser = new PageDataParser($context->input(), $pageRepo);
        $this->tagLookupRepo = $tagLookupRepo;
        $this->templateDecorator = new TemplateDecorator(
            $context->config(),
            $context->input(),
            dirname(__DIR__, 4)
        );
        $this->publicChannelPageRouteService = new ChannelPageRouter($context->input());
        $this->themeCatalogService = $themeCatalogService;
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
        $tagPrefix = $this->context->tagParser()->tagRoutePrefix();
        if ($tagPrefix === '') {
            $this->context->notFound();
            return;
        }

        $tag = $this->tagLookupRepo->findBySlug($tagSlug);
        if ($tag === null) {
            $this->context->notFound();
            return;
        }

        $perPage = max(1, (int) $this->context->config()->get('tag.pagination', 10));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $perPage;
        $pageResult = $this->pageParser->listPageByTagSlug($tagSlug, $perPage, $offset);
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
            'base_path' => '/' . $tagPrefix . '/' . rawurlencode($tagSlug),
        ]);
        $tagTemplate = $this->themeTemplate()->resolveTagTemplateNameForThemeChain(
            $tagSlug,
            $this->publicThemesRoot(),
            $this->currentPublicThemeSlug(),
            dirname(__DIR__, 4) . '/private/tpl'
        );

        $this->context->renderPublic($tagTemplate, [
            'site' => $this->context->siteDataWithTaxonomyMetaImage($tag),
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
