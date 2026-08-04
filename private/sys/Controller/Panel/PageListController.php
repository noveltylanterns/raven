<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/PageListController.php
 * Panel page list controller for the page list route.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\PageRead;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Pagination;

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
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PageRead $pageRead;
    private ChannelRead $channelRead;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param bool $categoryEnabled Whether category prefilter support is enabled in runtime config.
     * @param bool $tagEnabled Whether tag prefilter support is enabled in runtime config.
     * @param PageRead $pageRead Page repository read side for paginated page list queries.
     * @param ChannelRead $channelRead Channel repository read side for parent-aware channel prefilter resolution.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        bool $categoryEnabled,
        bool $tagEnabled,
        PageRead $pageRead,
        ChannelRead $channelRead
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->pageRead = $pageRead;
        $this->channelRead = $channelRead;
    }

    /**
     * Renders the page list with optional channel, category, and tag prefilters.
     *
     * @return void
     */
    public function pageList(): void
    {
        $this->context->requirePanelLogin();
        // Page listing is view-permission gated.
        if (!$this->context->requireRoutePermissionOrForbidden('page', 'view')) {
            return;
        }

        // Preserve slash-separated channel paths so child channels remain unambiguous.
        $prefilterChannel = strtolower(trim((string) ($_GET['channel'] ?? ''), '/'));
        $prefilterCategoryId = $this->input->int($_GET['category'] ?? null, 1) ?? 0;
        $prefilterTagId = $this->input->int($_GET['tag'] ?? null, 1) ?? 0;
        // Disable category prefilter when taxonomy feature is globally off.
        if (!$this->categoryEnabled) {
            $prefilterCategoryId = 0;
        }
        // Disable tag prefilter when taxonomy feature is globally off.
        if (!$this->tagEnabled) {
            $prefilterTagId = 0;
        }
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $prefilterChannelRecord = $prefilterChannel !== ''
            ? $this->channelRead->findByPath($prefilterChannel)
            : null;
        $prefilterChannelId = is_array($prefilterChannelRecord)
            ? (int) ($prefilterChannelRecord['id'] ?? 0)
            : null;
        // An unknown parent-aware channel path should behave like an empty filtered result, not like "all channels".
        $hasMissingChannelPrefilter = $prefilterChannel !== '' && $prefilterChannelId === null;
        $pageResult = $hasMissingChannelPrefilter
            ? ['rows' => [], 'total' => 0]
            : $this->pageRead->listPage(
                $perPage,
                ($requestedPage - 1) * $perPage,
                $prefilterChannelId,
                $prefilterCategoryId > 0 ? $prefilterCategoryId : null,
                $prefilterTagId > 0 ? $prefilterTagId : null
            );
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $pageRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = Pagination::state($totalItems, $requestedPage, $perPage);
        // Requery with clamped offset when requested page exceeds available range.
        if (!$hasMissingChannelPrefilter && $totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->pageRead->listPage(
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
        // Mirror active prefilter ids into each row for client-side persistence helpers.
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
            'pagination' => Pagination::panelViewData(
                $this->context->panelUrl('/page'),
                $pagination,
                [
                    'channel' => $prefilterChannel,
                    'category' => $prefilterCategoryId > 0 ? (string) $prefilterCategoryId : '',
                    'tag' => $prefilterTagId > 0 ? (string) $prefilterTagId : '',
                ]
            ),
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'page',
            'pageNav' => 'list',
        ]);
    }
}
