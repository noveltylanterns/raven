<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/ConfigRouter.php
 * Panel configuration-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers the panel configuration route family.
 */
final class ConfigRouter
{
    /**
     * Registers config routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving configuration routes.
     * @param RouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        self::register($router, $deps->panelConfigController);
    }

    /**
     * Registers the panel configuration routes.
     *
     * @param Router $router Mutable router receiving configuration routes.
     * @param callable(): object $panelConfigController Lazy configuration controller factory.
     * @return void
     */
    public static function register(Router $router, callable $panelConfigController): void
    {
        $router->add('GET', '/configuration', static function () use ($panelConfigController): void {
            $panelConfigController()->configuration();
        });

        $router->add('POST', '/configuration/save', static function () use ($panelConfigController): void {
            $panelConfigController()->configurationSave($_POST);
        });
    }
}
