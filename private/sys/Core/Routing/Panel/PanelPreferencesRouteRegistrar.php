<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Routing/Panel/PanelPreferencesRouteRegistrar.php
 * Panel preferences-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Routing\Router;

/**
 * Registers current-user preference routes for the panel runtime.
 */
final class PanelPreferencesRouteRegistrar
{
    /**
     * Registers the panel preferences route family.
     *
     * @param Router $router Mutable router receiving preferences routes.
     * @param callable(): object $panelPreferencesController Lazy preferences controller factory.
     * @return void
     */
    public static function register(Router $router, callable $panelPreferencesController): void
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
