<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/ProfileRouter.php
 * Public profile-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;

/**
 * Registers public profile routes.
 */
final class ProfileRouter
{
    /**
     * Registers the public profile route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving profile routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        $publicProfileController = $deps->publicProfileController;
        $publicRequestContext = $deps->publicRequestContext;
        $input = $deps->input;
        $profileRouteEnabled = !empty($deps->routeConfig['profile_route_enabled']);
        $profilePrefix = (string) ($deps->routeConfig['profile_prefix'] ?? '');

        if (!$profileRouteEnabled || $profilePrefix === '') {
            return;
        }

        $profileRouteBase = '/' . $profilePrefix;
        $router->add('GET', $profileRouteBase . '/{username}', static function (array $params) use ($publicProfileController, $publicRequestContext, $input): void {
            $username = $input->text(rawurldecode((string) ($params['username'] ?? '')), 254);

            if ($username === '') {
                $publicRequestContext()->notFound();
                return;
            }

            $publicProfileController()->profile($username);
        });
    }
}
