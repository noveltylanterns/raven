<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/LogsRouter.php
 * Panel logs-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers event-log routes for the panel runtime.
 */
final class LogsRouter
{
    /**
     * Registers the panel logs route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving logs routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelLogsController = $deps->panelLogsController;

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
