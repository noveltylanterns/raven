<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/UpdateRouter.php
 * Panel updater-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers updater routes for the panel runtime.
 */
final class UpdateRouter
{
    /**
     * Registers the panel updater route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving updater routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelUpdateController = $deps->panelUpdateController;

        $router->add('GET', '/update', static function () use ($panelUpdateController): void {
            $panelUpdateController()->update();
        });

        $router->add('POST', '/update/action', static function () use ($panelUpdateController): void {
            $panelUpdateController()->updateAction($_POST);
        });
    }
}
