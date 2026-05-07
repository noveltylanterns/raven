<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/AuthRouter.php
 * Panel auth-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;

/**
 * Registers login/logout routes for the panel runtime.
 */
final class AuthRouter
{
    /**
     * Registers the panel auth route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving the auth routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $authController = $deps->authController;

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
