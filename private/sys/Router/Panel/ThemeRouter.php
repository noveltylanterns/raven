<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/ThemeRouter.php
 * Panel theme-manager route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers panel routes for the public-theme manager.
 */
final class ThemeRouter
{
    /**
     * Registers the panel theme-manager route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving theme routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelThemeController = $deps->panelThemeController;

        $router->add('GET', '/themes', static function () use ($panelThemeController): void {
            $panelThemeController()->themes();
        });

        $router->add('POST', '/themes/enable', static function () use ($panelThemeController): void {
            $panelThemeController()->themesEnable($_POST);
        });

        $router->add('POST', '/themes/create', static function () use ($panelThemeController): void {
            $panelThemeController()->themesCreate($_POST);
        });

        $router->add('POST', '/themes/upload', static function () use ($panelThemeController): void {
            $panelThemeController()->themesUpload($_POST, $_FILES);
        });

        $router->add('GET', '/themes/export', static function () use ($panelThemeController): void {
            $panelThemeController()->themesExport($_GET);
        });

        $router->add('POST', '/themes/uninstall', static function () use ($panelThemeController): void {
            $panelThemeController()->themesUninstall($_POST);
        });
    }
}
