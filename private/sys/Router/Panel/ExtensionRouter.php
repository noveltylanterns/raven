<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/ExtensionRouter.php
 * Panel extension-manager route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers panel routes for extension management.
 */
final class ExtensionRouter
{
    /**
     * Registers extension-manager routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving extension-manager routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        self::register($router, $deps->panelExtensionController);
    }

    /**
     * Registers the panel extension-manager route family.
     *
     * @param RouteHandler $router Mutable router receiving extension-manager routes.
     * @param callable(): object $panelExtensionController Lazy extension controller factory.
     * @return void
     */
    public static function register(RouteHandler $router, callable $panelExtensionController): void
    {
        $router->add('GET', '/extensions', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensions();
        });

        $router->add('POST', '/extensions/toggle', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensionsToggle($_POST);
        });

        $router->add('POST', '/extensions/upload', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensionsUpload($_POST, $_FILES);
        });

        $router->add('GET', '/extensions/export', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensionsExport($_GET);
        });

        $router->add('POST', '/extensions/create', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensionsCreate($_POST);
        });

        $router->add('POST', '/extensions/uninstall', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensionsUninstall($_POST);
        });

        $router->add('POST', '/extensions/permission', static function () use ($panelExtensionController): void {
            $panelExtensionController()->extensionsPermission($_POST);
        });
    }
}
