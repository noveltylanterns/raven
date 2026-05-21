<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/GroupRouter.php
 * Public group-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers public group routes.
 */
final class GroupRouter
{
    /**
     * Registers the public group route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving group routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        $publicGroupController = $deps->publicGroupController;
        $publicRequestContext = $deps->publicRequestContext;
        $input = $deps->input;
        $groupRouteEnabled = !empty($deps->routeConfig['group_route_enabled']);
        $groupPrefix = (string) ($deps->routeConfig['group_prefix'] ?? '');

        if (!$groupRouteEnabled || $groupPrefix === '') {
            return;
        }

        $groupRouteBase = '/' . $groupPrefix;
        $router->add('GET', $groupRouteBase . '/{slug}', static function (array $params) use ($publicGroupController, $publicRequestContext, $input): void {
            $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            if ($slug === null) {
                return;
            }

            $publicGroupController()->group($slug);
        });
    }
}
