<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/DashboardController.php
 * Split panel dashboard controller.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Lib\Auth\Panel\SessionGuard;

/**
 * Handles the panel dashboard landing page.
 */
final class DashboardController
{
    private SharedController $context;
    private SessionGuard $sessionGuard;

    /**
     * @param SharedController $context Shared panel request context.
     * @return void
     */
    public function __construct(SharedController $context)
    {
        $this->context = $context;
        $this->sessionGuard = new SessionGuard();
    }

    /**
     * Renders the dashboard landing page.
     *
     * @return void
     */
    public function dashboard(): void
    {
        $this->context->requirePanelLogin();
        $panelIdentity = $this->sessionGuard->panelIdentityFromSession($_SESSION['rvn-panel-identity'] ?? null);

        $this->context->renderPanel('panel/dashboard', [
            'user' => [
                'email' => (string) ($panelIdentity['email'] ?? ''),
            ],
            'canManageUsers' => $this->context->auth()->panelService()->canManageUsers(),
            'canManageGroups' => $this->context->auth()->panelService()->canManageGroups(),
            'canManageConfiguration' => $this->context->auth()->panelService()->canManageConfiguration(),
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'dashboard',
        ]);
    }
}
