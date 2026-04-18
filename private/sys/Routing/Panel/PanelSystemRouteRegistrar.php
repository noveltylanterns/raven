<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelSystemRouteRegistrar.php
 * Panel system-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Router;

/**
 * Registers configuration/system-management routes for the panel runtime.
 */
final class PanelSystemRouteRegistrar
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
        $router->add('GET', '/configuration', static function () use ($panelSystemController): void {
            $panelSystemController()->configuration();
        });

        $router->add('GET', '/update', static function () use ($panelSystemController): void {
            $panelSystemController()->update();
        });

        $router->add('POST', '/update/action', static function () use ($panelSystemController): void {
            $panelSystemController()->updateAction($_POST);
        });

        $router->add('POST', '/configuration/save', static function () use ($panelSystemController): void {
            $panelSystemController()->configurationSave($_POST);
        });

        $router->add('GET', '/routing', static function () use ($panelSystemController): void {
            $panelSystemController()->routing();
        });

        $router->add('GET', '/routing/export', static function () use ($panelSystemController): void {
            $panelSystemController()->routingExport();
        });

        $router->add('GET', '/logs', static function () use ($panelSystemController): void {
            $panelSystemController()->logs();
        });

        $router->add('GET', '/logs/export', static function () use ($panelSystemController): void {
            $panelSystemController()->logsExport();
        });

        $router->add('POST', '/logs/clear', static function () use ($panelSystemController): void {
            $panelSystemController()->logsClear();
        });

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
