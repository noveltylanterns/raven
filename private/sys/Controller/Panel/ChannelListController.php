<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/ChannelListController.php
 * Panel channel list controller for the channel list route.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Repository\ChannelRead;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Pagination;

/**
 * Handles the channel list route for the panel.
 *
 * Owns GET /channel only. Channel create/edit/save/delete routes live in
 * ChannelEditController to keep read-only and write concerns separate.
 */
final class ChannelListController
{
    private SharedController $context;
    private InputSanitizer $input;
    private ChannelRead $channelRead;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param ChannelRead $channelRead Channel repository read side for paginated channel listings.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        ChannelRead $channelRead
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->channelRead = $channelRead;
    }

    /**
     * Lists channels for Channel management section.
     *
     * @return void
     */
    public function channelList(): void
    {
        $this->context->requirePanelLogin();
        // Enforce view permission before loading channel list payloads.
        if (!$this->context->requireRoutePermissionOrForbidden('channel', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->channelRead->listPage($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $channelRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = Pagination::state($totalItems, $requestedPage, $perPage);
        // Re-query using canonical page offset when requested page was clamped.
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->channelRead->listPage($perPage, $pagination['offset']);
            $channelRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->context->renderPanel('panel/channel/list', [
            'channelRows' => $channelRows,
            'pagination' => Pagination::panelViewData($this->context->panelUrl('/channel'), $pagination),
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'channel',
        ]);
    }
}
