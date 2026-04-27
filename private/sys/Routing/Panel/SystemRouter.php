<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/SystemRouter.php
 * Panel system-route registration for themes and extensions.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers the remaining panel system routes for themes and extensions.
 */
final class SystemRouter
{
    /**
     * Registers the panel system route family.
     *
     * @param Router $router Mutable router receiving system routes.
     * @param callable(): object $panelSystemController Lazy system controller factory.
     * @return void
     */
    public static function register(Router $router, callable $panelSystemController): void
    {
        $router->add('GET', '/themes', static function () use ($panelSystemController): void {
            $panelSystemController()->themes();
        });

        $router->add('POST', '/themes/enable', static function () use ($panelSystemController): void {
            $panelSystemController()->themesEnable($_POST);
        });

        $router->add('POST', '/themes/create', static function () use ($panelSystemController): void {
            $panelSystemController()->themesCreate($_POST);
        });

        $router->add('POST', '/themes/upload', static function () use ($panelSystemController): void {
            $panelSystemController()->themesUpload($_POST, $_FILES);
        });

        $router->add('GET', '/themes/export', static function () use ($panelSystemController): void {
            $panelSystemController()->themesExport($_GET);
        });

        $router->add('POST', '/themes/uninstall', static function () use ($panelSystemController): void {
            $panelSystemController()->themesUninstall($_POST);
        });

        $router->add('GET', '/extensions', static function () use ($panelSystemController): void {
            $panelSystemController()->extensions();
        });

        $router->add('POST', '/extensions/toggle', static function () use ($panelSystemController): void {
            $panelSystemController()->extensionsToggle($_POST);
        });

        $router->add('POST', '/extensions/upload', static function () use ($panelSystemController): void {
            $panelSystemController()->extensionsUpload($_POST, $_FILES);
        });

        $router->add('GET', '/extensions/export', static function () use ($panelSystemController): void {
            $panelSystemController()->extensionsExport($_GET);
        });

        $router->add('POST', '/extensions/create', static function () use ($panelSystemController): void {
            $panelSystemController()->extensionsCreate($_POST);
        });

        $router->add('POST', '/extensions/uninstall', static function () use ($panelSystemController): void {
            $panelSystemController()->extensionsUninstall($_POST);
        });

        $router->add('POST', '/extensions/permission', static function () use ($panelSystemController): void {
            $panelSystemController()->extensionsPermission($_POST);
        });
    }
}
