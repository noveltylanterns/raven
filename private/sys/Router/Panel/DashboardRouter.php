<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/DashboardRouter.php
 * Panel dashboard-route registration.
 * Docs: https://raven.lanterns.io
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
     * Registers dashboard routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving the dashboard route.
     * @param PanelRouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelRouteDeps $deps): void
    {
        self::register($router, $deps->panelDashboardController);
    }

    /**
     * Registers the dashboard landing route.
     *
     * @param RouteHandler $router Mutable router receiving the dashboard route.
     * @param callable(): object $panelDashboardController Lazy dashboard controller factory.
     * @return void
     */
    public static function register(RouteHandler $router, callable $panelDashboardController): void
    {
        $router->add('GET', '/', static function () use ($panelDashboardController): void {
            $panelDashboardController()->dashboard();
        });
    }
}
