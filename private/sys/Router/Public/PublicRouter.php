<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PublicRouter.php
 * Public-route orchestration over one isolated router instance.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteRequest;
use Raven\Core\Router\RouteResponse;
use Raven\Lib\Extension\Public\Routes as PublicRoutes;

/**
 * Owns public-route registration order and dispatch lifecycle.
 */
final class PublicRouter
{
    private RouteHandler $router;

    /**
     * Creates one isolated router instance for public route orchestration.
     */
    public function __construct()
    {
        $this->router = new RouteHandler();
    }

    /**
     * Registers the full public route map in canonical registration order.
     *
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public function register(PublicPayload $deps): void
    {
        AuthRouter::registerWithDeps($this->router, $deps);
        PublicRoutes::register($this->router, $deps->rvn, $deps->publicRequestContext, $deps->input);
        CategoryRouter::registerWithDeps($this->router, $deps);
        ChannelRouter::registerWithDeps($this->router, $deps);
        FeedRouter::registerWithDeps($this->router, $deps);
        ProfileRouter::registerWithDeps($this->router, $deps);
        GroupRouter::registerWithDeps($this->router, $deps);
        TagRouter::registerWithDeps($this->router, $deps);
        PageRouter::registerWithDeps($this->router, $deps);
    }

    /**
     * Dispatches one normalized request through the isolated public route map.
     *
     * @param RouteRequest $request Normalized routing request payload.
     * @return RouteResponse Dispatch result indicating match state and params.
     */
    public function dispatch(RouteRequest $request): RouteResponse
    {
        return $this->router->dispatch($request);
    }
}
