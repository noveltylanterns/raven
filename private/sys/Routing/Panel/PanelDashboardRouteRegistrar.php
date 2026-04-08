<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelDashboardRouteRegistrar.php
 * Panel dashboard-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers the panel dashboard route family.
 */
final class PanelDashboardRouteRegistrar
{
    /**
     * Registers the dashboard landing route.
     *
     * @param Router $router Mutable router receiving the dashboard route.
     * @param callable(): object $panelDashboardController Lazy dashboard controller factory.
     * @return void
     */
    public static function register(Router $router, callable $panelDashboardController): void
    {
        $router->add('GET', '/', static function () use ($panelDashboardController): void {
            $panelDashboardController()->dashboard();
        });
    }
}
