<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/TagRouter.php
 * Public tag-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;

/**
 * Registers public tag routes.
 *
 * Delegates the slug+pagination route layout to PrefixRouter,
 * supplying the tag prefix config key and tag controller closure.
 */
final class TagRouter
{
    /**
     * Registers tag routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving tag routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        PrefixRouter::register(
            $router,
            'tag_route_enabled',
            'tag_prefix',
            $deps->routeConfig,
            fn(string $slug) => $deps->publicTagController()->tag($slug, 1),
            fn(string $slug, int $page) => $deps->publicTagController()->tag($slug, $page),
            $deps->publicRequestContext,
            $deps->input
        );
    }
}
