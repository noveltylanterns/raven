<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/DashboardRouter.php
 * Panel dashboard-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers the panel dashboard route family.
 */
final class DashboardRouter
{
    /**
     * Registers the dashboard landing route from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving the dashboard route.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelDashboardController = $deps->panelDashboardController;

        $router->add('GET', '/', static function () use ($panelDashboardController): void {
            $panelDashboardController()->dashboard();
        });
    }
}
