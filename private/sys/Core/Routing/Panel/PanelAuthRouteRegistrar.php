<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Routing/Panel/PanelAuthRouteRegistrar.php
 * Panel auth-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Routing\Router;

/**
 * Registers login/logout routes for the panel runtime.
 */
final class PanelAuthRouteRegistrar
{
    /**
     * Registers the panel auth route family.
     *
     * @param Router $router Mutable router receiving the auth routes.
     * @param callable(): object $authController Lazy panel-auth controller factory.
     * @return void
     */
    public static function register(Router $router, callable $authController): void
    {
        $router->add('GET', '/login', static function () use ($authController): void {
            $authController()->showLogin();
        });

        $router->add('POST', '/login', static function () use ($authController): void {
            $authController()->login($_POST);
        });

        $router->add('GET', '/login/2fa', static function () use ($authController): void {
            $authController()->showLoginTwoFactor();
        });

        $router->add('POST', '/login/2fa', static function () use ($authController): void {
            $authController()->loginTwoFactor($_POST);
        });

        $router->add('POST', '/login/2fa/select', static function () use ($authController): void {
            $authController()->loginTwoFactorSelect($_POST);
        });

        $router->add('POST', '/login/2fa/webauthn/options', static function () use ($authController): void {
            $authController()->loginTwoFactorWebauthnOptions($_POST);
        });

        $router->add('POST', '/login/2fa/webauthn/verify', static function () use ($authController): void {
            $authController()->loginTwoFactorWebauthnVerify($_POST);
        });

        $router->add('POST', '/logout', static function () use ($authController): void {
            $authController()->logout($_POST);
        });
    }
}
