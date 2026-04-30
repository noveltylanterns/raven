<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/TagRouter.php
 * Public tag-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;

/**
 * Registers public tag routes.
 *
 * Delegates the slug+pagination route layout to PrefixedSlugPageRouter,
 * supplying the tag prefix config key and tag controller closure.
 */
final class TagRouter
{
    /**
     * Registers tag routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving tag routes.
     * @param RouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        PrefixedSlugPageRouter::register(
            $router,
            'tag_prefix',
            $deps->routeConfig,
            fn(string $slug) => $deps->publicTagController()->tag($slug, 1),
            fn(string $slug, int $page) => $deps->publicTagController()->tag($slug, $page),
            $deps->publicRequestContext,
            $deps->input
        );
    }
}
