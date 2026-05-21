<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/AuthRouter.php
 * Public auth-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;

/**
 * Registers login and registration routes for the public runtime.
 */
final class AuthRouter
{
    /**
     * Registers the public auth route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving the auth routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        $publicAuthController = $deps->publicAuthController;

        $router->add('GET', '/login', static function () use ($publicAuthController): void {
            $publicAuthController()->login();
        });

        $router->add('POST', '/login', static function () use ($publicAuthController): void {
            $publicAuthController()->loginSubmit($_POST);
        });

        $router->add('GET', '/login/2fa', static function () use ($publicAuthController): void {
            $publicAuthController()->loginTwoFactor();
        });

        $router->add('POST', '/login/2fa', static function () use ($publicAuthController): void {
            $publicAuthController()->loginTwoFactorSubmit($_POST);
        });

        $router->add('POST', '/login/2fa/select', static function () use ($publicAuthController): void {
            $publicAuthController()->loginTwoFactorSelect($_POST);
        });

        $router->add('POST', '/login/2fa/webauthn/options', static function () use ($publicAuthController): void {
            $publicAuthController()->loginTwoFactorWebauthnOptions($_POST);
        });

        $router->add('POST', '/login/2fa/webauthn/verify', static function () use ($publicAuthController): void {
            $publicAuthController()->loginTwoFactorWebauthnVerify($_POST);
        });

        $router->add('GET', '/register', static function () use ($publicAuthController): void {
            $publicAuthController()->register();
        });

        $router->add('POST', '/register', static function () use ($publicAuthController): void {
            $publicAuthController()->registerSubmit($_POST);
        });
    }
}
