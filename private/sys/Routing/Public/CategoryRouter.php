<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/CategoryRouter.php
 * Public category-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;

/**
 * Registers public category routes.
 *
 * Delegates the slug+pagination route layout to PrefixedSlugPageRouter,
 * supplying the category prefix config key and category controller closure.
 */
final class CategoryRouter
{
    /**
     * Registers category routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving category routes.
     * @param RouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        PrefixedSlugPageRouter::register(
            $router,
            'category_prefix',
            $deps->routeConfig,
            fn(string $slug) => $deps->publicCategoryController()->category($slug, 1),
            fn(string $slug, int $page) => $deps->publicCategoryController()->category($slug, $page),
            $deps->publicRequestContext,
            $deps->input
        );
    }
}
