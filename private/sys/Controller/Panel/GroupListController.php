<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/GroupListController.php
 * Panel group list controller for the group list route.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Core\Repository\GroupRead;
use Raven\Core\Router\GroupPolicy;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\Pagination;

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
    private GroupRead $groupRead;
    private GroupPolicy $groupRouteParser;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param GroupRead $groupRead Group repository read side for paginated group listings.
     * @param GroupPolicy $groupRouteParser Group route parser for routing-enabled flag in list view.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        GroupRead $groupRead,
        GroupPolicy $groupRouteParser
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->groupRead = $groupRead;
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
        // Group listing is view-permission gated.
        if (!$this->context->requireRoutePermissionOrForbidden('group', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->groupRead->listPage($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $groupRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = Pagination::state($totalItems, $requestedPage, $perPage);
        // Requery with clamped offset when requested page exceeds available range.
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->groupRead->listPage($perPage, $pagination['offset']);
            $groupRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->context->renderPanel('panel/group/list', [
            'groups' => $groupRows,
            'pagination' => Pagination::panelViewData($this->context->panelUrl('/group'), $pagination),
            'groupRoutingEnabledSystemWide' => $this->groupRouteParser->groupRouteEnabled(),
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'group',
        ]);
    }
}
