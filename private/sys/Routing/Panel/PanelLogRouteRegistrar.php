<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelLogRouteRegistrar.php
 * Panel logs-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers event-log routes for the panel runtime.
 */
final class PanelLogRouteRegistrar
{
    /**
     * Registers the panel logs route family.
     *
     * @param Router $router Mutable router receiving log routes.
     * @param callable(): object $panelLogsController Lazy logs controller factory.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelLogsController
    ): void {
        $router->add('GET', '/logs', static function () use ($panelLogsController): void {
            $panelLogsController()->logs();
        });

        $router->add('GET', '/logs/export', static function () use ($panelLogsController): void {
            $panelLogsController()->logsExport();
        });

        $router->add('POST', '/logs/clear', static function () use ($panelLogsController): void {
            $panelLogsController()->logsClear();
        });
    }
}
