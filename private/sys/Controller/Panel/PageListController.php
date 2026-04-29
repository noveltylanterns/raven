<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PageListController.php
 * Panel page list controller for the page list route.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Repository\PageRead;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Parser\PageDataParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Handles the page list route for the panel.
 *
 * Owns GET /page only. Page create/edit, save, gallery upload/delete, and page
 * delete live in PageEditController to keep read-only and write concerns separate.
 */
final class PageListController
{
    private SharedController $context;
    private InputSanitizer $input;
    private PageRead $pageRead;
    private ChannelDataParser $channelParser;
    private ?PageDataParser $pageParser = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param PageRead $pageRead Page repository read side for paginated page list queries.
     * @param ChannelDataParser $channelParser Channel data parser for channel-slug prefilter resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        PageRead $pageRead,
        ChannelDataParser $channelParser
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->pageRead = $pageRead;
        $this->channelParser = $channelParser;
    }

    /**
     * Renders the page list with optional channel, category, and tag prefilters.
     *
     * @return void
     */
    public function pageList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('page', 'view')) {
            return;
        }

        $prefilterChannel = $this->input->slug($_GET['channel'] ?? null) ?? '';
        $prefilterCategoryId = $this->input->int($_GET['category'] ?? null, 1) ?? 0;
        $prefilterTagId = $this->input->int($_GET['tag'] ?? null, 1) ?? 0;
        if (!$this->context->categoryEnabled()) {
            $prefilterCategoryId = 0;
        }
        if (!$this->context->tagEnabled()) {
            $prefilterTagId = 0;
        }
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $prefilterChannelId = $prefilterChannel !== '' ? $this->channelParser->idBySlug($prefilterChannel) : null;
        // An unknown channel slug should behave like an empty filtered result, not like "all channels".
        $hasMissingChannelPrefilter = $prefilterChannel !== '' && $prefilterChannelId === null;
        $pageResult = $hasMissingChannelPrefilter
            ? ['rows' => [], 'total' => 0]
            : $this->pageParser()->listPage(
                $perPage,
                ($requestedPage - 1) * $perPage,
                $prefilterChannelId,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $pageRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if (!$hasMissingChannelPrefilter && $totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->pageParser()->listPage(
                $perPage,
                $pagination['offset'],
                $prefilterChannelId,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
            $pageRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }
        $prefilterCategoryIds = $prefilterCategoryId > 0 ? [$prefilterCategoryId] : [];
        $prefilterTagIds = $prefilterTagId > 0 ? [$prefilterTagId] : [];
        foreach ($pageRows as &$pageRow) {
            // Server-side page prefilters already constrain result rows, so list rows only
            // need the active prefilter ids for client-side in-page filter persistence.
            $pageRow['category_ids'] = $prefilterCategoryIds;
            $pageRow['tag_ids'] = $prefilterTagIds;
        }
        unset($pageRow);

        $this->context->renderPanel('panel/page/list', [
            'pages' => $pageRows,
            'prefilterChannel' => strtolower($prefilterChannel),
            'prefilterCategoryId' => $prefilterCategoryId,
            'prefilterTagId' => $prefilterTagId,
            'pagination' => $this->context->panelPaginationViewData(
                '/page',
                $pagination,
                [
                    'channel' => $prefilterChannel,
                    'category' => $prefilterCategoryId > 0 ? (string) $prefilterCategoryId : '',
                    'tag' => $prefilterTagId > 0 ? (string) $prefilterTagId : '',
                ]
            ),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'page',
            'pageNav' => 'list',
        ]);
    }

    /**
     * Returns the page parser on first use so the repository is not instantiated
     * until an actual page list query is needed.
     *
     * @return PageDataParser Page data parser.
     */
    private function pageParser(): PageDataParser
    {
        if (!$this->pageParser instanceof PageDataParser) {
            $this->pageParser = new PageDataParser($this->input, $this->pageRead);
        }

        return $this->pageParser;
    }
}
