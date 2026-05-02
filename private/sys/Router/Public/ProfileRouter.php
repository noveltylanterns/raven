<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/ProfileRouter.php
 * Public profile-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public profile routes.
 */
final class ProfileRouter
{
    /**
     * Registers profile routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving profile routes.
     * @param PublicRouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicRouteDeps $deps): void
    {
        self::register(
            $router,
            $deps->publicUserController,
            $deps->publicRequestContext,
            $deps->input,
            $deps->routeConfig
        );
    }

    /**
     * Registers the public profile route family.
     *
     * @param RouteHandler $router Mutable router receiving profile routes.
     * @param callable(): object $publicUserController Lazy public-user controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{profile_prefix: string} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        callable $publicUserController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $profilePrefix = (string) ($routeConfig['profile_prefix'] ?? '');
        if ($profilePrefix === '') {
            return;
        }

        $profileRouteBase = '/' . $profilePrefix;
        $router->add('GET', $profileRouteBase . '/{username}', static function (array $params) use ($publicUserController, $publicRequestContext, $input): void {
            $username = $input->text(rawurldecode((string) ($params['username'] ?? '')), 254);

            if ($username === '') {
                $publicRequestContext()->notFound();
                return;
            }

            $publicUserController()->profile($username);
        });
    }
}
