<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/PreferencesRouter.php
 * Panel preferences-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers current-user preference routes for the panel runtime.
 */
final class PreferencesRouter
{
    /**
     * Registers preference routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving preferences routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        self::register($router, $deps->panelPreferencesController);
    }

    /**
     * Registers the panel preferences route family.
     *
     * @param RouteHandler $router Mutable router receiving preferences routes.
     * @param callable(): object $panelPreferencesController Lazy preferences controller factory.
     * @return void
     */
    public static function register(RouteHandler $router, callable $panelPreferencesController): void
    {
        $router->add('GET', '/preferences', static function () use ($panelPreferencesController): void {
            $panelPreferencesController()->preferences();
        });

        $router->add('POST', '/preferences/save', static function () use ($panelPreferencesController): void {
            $panelPreferencesController()->preferencesSave($_POST, $_FILES);
        });

        $router->add('POST', '/preferences/2fa/totp/setup', static function () use ($panelPreferencesController): void {
            $panelPreferencesController()->preferencesTotpSetup($_POST);
        });

        $router->add('POST', '/preferences/2fa/recovery/generate', static function () use ($panelPreferencesController): void {
            $panelPreferencesController()->preferencesRecoveryCodeGenerate($_POST);
        });

        $router->add('POST', '/preferences/2fa/webauthn/options', static function () use ($panelPreferencesController): void {
            $panelPreferencesController()->preferencesWebauthnCreateOptions($_POST);
        });

        $router->add('POST', '/preferences/2fa/webauthn/register', static function () use ($panelPreferencesController): void {
            $panelPreferencesController()->preferencesWebauthnRegister($_POST);
        });
    }
}
