<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/CategoryRouter.php
 * Public category-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;

/**
 * Registers public category routes.
 *
 * Delegates the slug+pagination route layout to PrefixRouter,
 * supplying the category prefix config key and category controller closure.
 */
final class CategoryRouter
{
    /**
     * Registers category routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving category routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        PrefixRouter::register(
            $router,
            'category_route_enabled',
            'category_prefix',
            $deps->routeConfig,
            fn(string $slug) => ($deps->publicCategoryController)()->category($slug, 1),
            fn(string $slug, int $page) => ($deps->publicCategoryController)()->category($slug, $page),
            $deps->publicRequestContext,
            $deps->input
        );
    }
}
