<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/DashboardRouter.php
 * Panel dashboard-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers the panel dashboard route family.
 */
final class DashboardRouter
{
    /**
     * Registers dashboard routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving the dashboard route.
     * @param RouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        self::register($router, $deps->panelDashboardController);
    }

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
