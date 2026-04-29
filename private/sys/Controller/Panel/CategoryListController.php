<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/CategoryListController.php
 * Panel category list controller for category and category-set list routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\SetRead;
use Raven\Lib\Security\InputSanitizer;

/**
 * Handles category and category-set list routes for the panel.
 *
 * Owns GET /category and GET /category/set only. Category create/edit/save/delete
 * and category-set CRUD live in CategoryEditController.
 */
final class CategoryListController
{
    private SharedController $context;
    private InputSanitizer $input;
    private Closure $categoryReadResolver;
    private ?CategoryRead $categoryRead = null;
    private Closure $categorySetRepoResolver;
    private ?SetRead $categorySetRepo = null;
    private bool $categoryEnabled;
    private ChannelRead $channelRead;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable $categoryReadResolver Lazy category read resolver; resolved on category list routes.
     * @param callable $categorySetRepoResolver Lazy category-set read resolver; resolved for set filter tabs.
     * @param bool $categoryEnabled Whether category features are enabled in runtime config.
     * @param ChannelRead $channelRead Channel repository for category-set channel usage counts.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $categoryReadResolver,
        callable $categorySetRepoResolver,
        bool $categoryEnabled,
        ChannelRead $channelRead
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->categoryReadResolver = Closure::fromCallable($categoryReadResolver);
        $this->categorySetRepoResolver = Closure::fromCallable($categorySetRepoResolver);
        $this->categoryEnabled = $categoryEnabled;
        $this->channelRead = $channelRead;
    }

    /**
     * Lists categories for Category management section.
     *
     * @return void
     */
    public function categoryList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('category', 'view')) {
            return;
        }

        $categoryCountsBySetId = $this->categoryRead()->countsBySetId();
        $selectedSetId = $this->input->int($_GET['set'] ?? null, 0);
        if (
            $selectedSetId !== null
            && (
                !$this->categorySetRepo()->existsId($selectedSetId)
                || (int) ($categoryCountsBySetId[$selectedSetId] ?? 0) < 1
            )
        ) {
            $selectedSetId = null;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->categoryRead()->listPage($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $categoryRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->categoryRead()->listPage($perPage, $pagination['offset'], $selectedSetId);
            $categoryRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        // Only show set filter tabs for sets that actually have categories.
        $setOptions = [];
        foreach ($this->categorySetRepo()->listOptions() as $setOption) {
            $setId = (int) ($setOption['id'] ?? 0);
            if ((int) ($categoryCountsBySetId[$setId] ?? 0) < 1) {
                continue;
            }

            $setOptions[] = $setOption;
        }

        $this->context->renderPanel('panel/category/list', [
            'categoryRows' => $categoryRows,
            'setOptions' => $setOptions,
            'selectedSetId' => $selectedSetId,
            'pagination' => $this->context->panelPaginationViewData('/category', $pagination, [
                'set' => $selectedSetId !== null ? (string) $selectedSetId : '',
            ]),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'category',
        ]);
    }

    /**
     * Lists category-set records for channel-assignment management.
     *
     * @return void
     */
    public function categorySetList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->categoryEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('category', 'view')) {
            return;
        }

        // Annotate each set row with its category and channel usage counts.
        $countsBySetId = $this->categoryRead()->countsBySetId();
        $channelCountsBySetId = $this->channelRead->explicitTaxonomySetCounts('category');
        $setRows = [];
        foreach ($this->categorySetRepo()->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['category_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = (int) ($channelCountsBySetId[$setId] ?? 0);
            $setRows[] = $setRow;
        }

        $this->context->renderPanel('panel/category/set_list', [
            'setRows' => $setRows,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'category',
        ]);
    }

    /**
     * Returns the category read side on first use so non-category routes do not
     * instantiate DB-backed taxonomy storage.
     *
     * @return CategoryRead Category repository read side.
     */
    private function categoryRead(): CategoryRead
    {
        if ($this->categoryRead instanceof CategoryRead) {
            return $this->categoryRead;
        }

        $repo = ($this->categoryReadResolver)();
        if (!$repo instanceof CategoryRead) {
            throw new \RuntimeException('Panel category read resolver returned an invalid value.');
        }

        $this->categoryRead = $repo;
        return $this->categoryRead;
    }

    /**
     * Returns the category-set repository on first use so non-taxonomy routes
     * do not instantiate file-backed taxonomy set storage.
     *
     * @return SetRead Category-set repository read side.
     */
    private function categorySetRepo(): SetRead
    {
        if ($this->categorySetRepo instanceof SetRead) {
            return $this->categorySetRepo;
        }

        $repo = ($this->categorySetRepoResolver)();
        if (!$repo instanceof SetRead) {
            throw new \RuntimeException('Panel category-set repository resolver returned an invalid value.');
        }

        $this->categorySetRepo = $repo;
        return $this->categorySetRepo;
    }
}
