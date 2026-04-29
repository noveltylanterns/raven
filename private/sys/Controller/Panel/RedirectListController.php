<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/RedirectListController.php
 * Panel redirect list controller for the redirect list route.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Lib\Parser\RedirectDataParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Handles the redirect list route for the panel.
 *
 * Owns GET /redirect only. Redirect create/edit/save/delete routes
 * live in RedirectEditController to keep read and write concerns separate.
 */
final class RedirectListController
{
    private SharedController $context;
    private InputSanitizer $input;
    private RedirectDataParser $redirectParser;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param RedirectDataParser $redirectParser Canonical parser for redirect listings.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        RedirectDataParser $redirectParser
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->redirectParser = $redirectParser;
    }

    /**
     * Lists redirects for Redirect management section.
     *
     * @return void
     */
    public function redirectList(): void
    {
        $this->context->requirePanelLogin();
        if (!$this->context->requireRoutePermissionOrForbidden('redirect', 'view')) {
            return;
        }

        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $perPage = 50;
        $pageResult = $this->redirectParser->listPage($perPage, ($requestedPage - 1) * $perPage);
        $totalItems = (int) ($pageResult['total'] ?? 0);
        $redirectRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $pageResult = $this->redirectParser->listPage($perPage, $pagination['offset']);
            $redirectRows = is_array($pageResult['rows'] ?? null) ? $pageResult['rows'] : [];
        }

        $this->context->renderPanel('panel/redirect/list', [
            'redirectRows' => $redirectRows,
            'pagination' => $this->context->panelPaginationViewData('/redirect', $pagination),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'redirect',
        ]);
    }
}
