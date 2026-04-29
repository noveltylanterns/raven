<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/GroupListController.php
 * Panel group list controller for the group list route.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Lib\Parser\GroupDataParser;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Handles the group list route for the panel.
 *
 * Owns GET /group only. Group create/edit/save/delete routes live in
 * GroupEditController to keep read-only and write concerns separate.
 */
final class GroupListController
{
    private SharedController $context;
    private InputSanitizer $input;
    private GroupDataParser $groupDataParser;
    private GroupRouteParser $groupRouteParser;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param GroupDataParser $groupDataParser Group data parser for paginated group listings.
     * @param GroupRouteParser $groupRouteParser Group route parser for routing-enabled flag in list view.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        GroupDataParser $groupDataParser,
        GroupRouteParser $groupRouteParser
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->groupDataParser = $groupDataParser;
        $this->groupRouteParser = $groupRouteParser;
    }

    /**
     * Lists groups for Usergroup management section.
     *
     * @return void
     */
    public function groupList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('group', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->groupDataParser->listPage($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $groupRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->groupDataParser->listPage($perPage, $pagination['offset']);
            $groupRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->context->renderPanel('panel/group/list', [
            'groups' => $groupRows,
            'pagination' => $this->context->panelPaginationViewData('/group', $pagination),
            'groupRoutingEnabledSystemWide' => $this->groupRouteParser->groupRoutesEnabledForRoutingTable(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'group',
        ]);
    }
}
