<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelRoutingRouteRegistrar.php
 * Panel routing diagnostics-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers routing diagnostics routes for the panel runtime.
 */
final class PanelRoutingRouteRegistrar
{
    /**
     * Registers the panel routing diagnostics route family.
     *
     * @param Router $router Mutable router receiving routing routes.
     * @param callable(): object $panelRoutingController Lazy routing controller factory.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelRoutingController
    ): void {
        $router->add('GET', '/routing', static function () use ($panelRoutingController): void {
            $panelRoutingController()->routing();
        });

        $router->add('GET', '/routing/export', static function () use ($panelRoutingController): void {
            $panelRoutingController()->routingExport();
        });
    }
}
