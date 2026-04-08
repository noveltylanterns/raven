<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicAuthRouteRegistrar.php
 * Public auth-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;

/**
 * Registers login and registration routes for the public runtime.
 */
final class PublicAuthRouteRegistrar
{
    /**
     * Registers the public auth route family.
     *
     * @param Router $router Mutable router receiving the auth routes.
     * @param callable(): object $publicAuthController Lazy public-auth controller factory.
     * @return void
     */
    public static function register(Router $router, callable $publicAuthController): void
    {
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
