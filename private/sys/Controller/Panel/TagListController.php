<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/TagListController.php
 * Panel tag list controller for tag and tag-set list routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\TagRead;
use Raven\Lib\Security\InputSanitizer;

/**
 * Handles tag and tag-set list routes for the panel.
 *
 * Owns GET /tag and GET /tag/set only. Tag create/edit/save/delete and
 * tag-set CRUD live in TagEditController.
 */
final class TagListController
{
    private SharedController $context;
    private InputSanitizer $input;
    private Closure $tagReadResolver;
    private ?TagRead $tagRead = null;
    private Closure $tagSetRepoResolver;
    private ?SetRead $tagSetRepo = null;
    private bool $tagEnabled;
    private ChannelRead $channelRead;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable $tagReadResolver Lazy tag read resolver; resolved on tag list routes.
     * @param callable $tagSetRepoResolver Lazy tag-set read resolver; resolved for set filter tabs.
     * @param bool $tagEnabled Whether tag features are enabled in runtime config.
     * @param ChannelRead $channelRead Channel repository for tag-set channel usage counts.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $tagReadResolver,
        callable $tagSetRepoResolver,
        bool $tagEnabled,
        ChannelRead $channelRead
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->tagReadResolver = Closure::fromCallable($tagReadResolver);
        $this->tagSetRepoResolver = Closure::fromCallable($tagSetRepoResolver);
        $this->tagEnabled = $tagEnabled;
        $this->channelRead = $channelRead;
    }

    /**
     * Lists tags for Tag management section.
     *
     * @return void
     */
    public function tagList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('tag', 'view')) {
            return;
        }

        $tagCountsBySetId = $this->tagRead()->countsBySetId();
        $selectedSetId = $this->input->int($_GET['set'] ?? null, 0);
        if (
            $selectedSetId !== null
            && (
                !$this->tagSetRepo()->existsId($selectedSetId)
                || (int) ($tagCountsBySetId[$selectedSetId] ?? 0) < 1
            )
        ) {
            $selectedSetId = null;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->tagRead()->listPage($perPage, ($requestedPage - 1) * $perPage, $selectedSetId);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->tagRead()->listPage($perPage, $pagination['offset'], $selectedSetId);
            $tagRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        // Only show set filter tabs for sets that actually have tags.
        $setOptions = [];
        foreach ($this->tagSetRepo()->listOptions() as $setOption) {
            $setId = (int) ($setOption['id'] ?? 0);
            if ((int) ($tagCountsBySetId[$setId] ?? 0) < 1) {
                continue;
            }

            $setOptions[] = $setOption;
        }

        $this->context->renderPanel('panel/tag/list', [
            'tagRows' => $tagRows,
            'setOptions' => $setOptions,
            'selectedSetId' => $selectedSetId,
            'pagination' => $this->context->panelPaginationViewData('/tag', $pagination, [
                'set' => $selectedSetId !== null ? (string) $selectedSetId : '',
            ]),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'tag',
        ]);
    }

    /**
     * Lists tag-set records for channel-assignment management.
     *
     * @return void
     */
    public function tagSetList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->tagEnabled) {
            $this->context->renderPanelNotFound();
            return;
        }
        if (!$this->context->requireRoutePermissionOrForbidden('tag', 'view')) {
            return;
        }

        // Annotate each set row with its tag and channel usage counts.
        $countsBySetId = $this->tagRead()->countsBySetId();
        $channelCountsBySetId = $this->channelRead->explicitTaxonomySetCounts('tag');
        $setRows = [];
        foreach ($this->tagSetRepo()->listAll() as $setRow) {
            $setId = (int) ($setRow['id'] ?? 0);
            $setRow['tag_count'] = (int) ($countsBySetId[$setId] ?? 0);
            $setRow['channel_count'] = (int) ($channelCountsBySetId[$setId] ?? 0);
            $setRows[] = $setRow;
        }

        $this->context->renderPanel('panel/tag/set_list', [
            'setRows' => $setRows,
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'tag',
        ]);
    }

    /**
     * Returns the tag read side on first use so non-tag routes do not
     * instantiate DB-backed taxonomy storage.
     *
     * @return TagRead Tag repository read side.
     */
    private function tagRead(): TagRead
    {
        if ($this->tagRead instanceof TagRead) {
            return $this->tagRead;
        }

        $repo = ($this->tagReadResolver)();
        if (!$repo instanceof TagRead) {
            throw new \RuntimeException('Panel tag read resolver returned an invalid value.');
        }

        $this->tagRead = $repo;
        return $this->tagRead;
    }

    /**
     * Returns the tag-set repository on first use so non-taxonomy routes do not
     * instantiate file-backed taxonomy set storage.
     *
     * @return SetRead Tag-set repository read side.
     */
    private function tagSetRepo(): SetRead
    {
        if ($this->tagSetRepo instanceof SetRead) {
            return $this->tagSetRepo;
        }

        $repo = ($this->tagSetRepoResolver)();
        if (!$repo instanceof SetRead) {
            throw new \RuntimeException('Panel tag-set repository resolver returned an invalid value.');
        }

        $this->tagSetRepo = $repo;
        return $this->tagSetRepo;
    }
}
