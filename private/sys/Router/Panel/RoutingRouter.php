<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/RoutingRouter.php
 * Panel routing diagnostics-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers routing diagnostics routes for the panel runtime.
 */
final class RoutingRouter
{
    /**
     * Registers the panel routing diagnostics route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving routing routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelRoutingController = $deps->panelRoutingController;

        $router->add('GET', '/routing', static function () use ($panelRoutingController): void {
            $panelRoutingController()->routing();
        });

        $router->add('GET', '/routing/export', static function () use ($panelRoutingController): void {
            $panelRoutingController()->routingExport();
        });
    }
}
