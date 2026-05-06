<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/DashboardController.php
 * Split panel dashboard controller.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

/**
 * Handles the panel dashboard landing page.
 */
final class DashboardController
{
    private SharedController $context;

    /**
     * @param SharedController $context Shared panel request context.
     * @return void
     */
    public function __construct(SharedController $context)
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
            'canManageUsers' => $this->context->auth()->panelService()->canManageUsers(),
            'canManageGroups' => $this->context->auth()->panelService()->canManageGroups(),
            'canManageConfiguration' => $this->context->auth()->panelService()->canManageConfiguration(),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'dashboard',
        ]);
    }
}
