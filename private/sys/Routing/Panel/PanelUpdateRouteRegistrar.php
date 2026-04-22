<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelUpdateRouteRegistrar.php
 * Panel updater-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers updater routes for the panel runtime.
 */
final class PanelUpdateRouteRegistrar
{
    /**
     * Registers the panel updater route family.
     *
     * @param Router $router Mutable router receiving updater routes.
     * @param callable(): object $panelUpdateController Lazy updater controller factory.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelUpdateController
    ): void {
        $router->add('GET', '/update', static function () use ($panelUpdateController): void {
            $panelUpdateController()->update();
        });

        $router->add('POST', '/update/action', static function () use ($panelUpdateController): void {
            $panelUpdateController()->updateAction($_POST);
        });
    }
}
