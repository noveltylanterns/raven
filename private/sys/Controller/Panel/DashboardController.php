<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/DashboardController.php
 * Split panel dashboard controller.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Controller\Panel;

/**
 * Handles the panel dashboard landing page.
 */
final class DashboardController
{
    private RequestContext $context;

    /**
     * @param RequestContext $context Shared panel request context.
     * @return void
     */
    public function __construct(RequestContext $context)
    {
        $this->context = $context;
    }

    /**
     * Renders the dashboard landing page.
     *
     * @return void
     */
    public function dashboard(): void
    {
        $this->context->requirePanelLogin();
        $panelIdentity = $this->context->panelIdentityFromSession();

        $this->context->renderPanel('panel/dashboard', [
            'user' => [
                'email' => (string) ($panelIdentity['email'] ?? ''),
            ],
            'canManageUsers' => $this->context->auth()->canManageUsers(),
            'canManageGroups' => $this->context->auth()->canManageGroups(),
            'canManageConfiguration' => $this->context->auth()->canManageConfiguration(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'dashboard',
        ]);
    }
}
