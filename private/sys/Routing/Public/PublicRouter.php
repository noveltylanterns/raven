<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicRouter.php
 * Public-route orchestration over one isolated router instance.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Request;
use Raven\Core\Routing\Response;
use Raven\Core\Routing\Router;

/**
 * Owns public-route registration order and dispatch lifecycle.
 */
final class PublicRouter
{
    private Router $router;

    /**
     * Creates one isolated router instance for public route orchestration.
     */
    public function __construct()
    {
        $this->router = new Router();
    }

    /**
     * Registers the full public route map in canonical registration order.
     *
     * @param RouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public function register(RouteDeps $deps): void
    {
        AuthRouter::registerWithDeps($this->router, $deps);
        FormRouter::registerWithDeps($this->router, $deps);
        ExtensionRouter::registerWithDeps($this->router, $deps);
        CategoryRouter::registerWithDeps($this->router, $deps);
        ChannelRouter::registerWithDeps($this->router, $deps);
        FeedRouter::registerWithDeps($this->router, $deps);
        ProfileRouter::registerWithDeps($this->router, $deps);
        GroupRouter::registerWithDeps($this->router, $deps);
        TagRouter::registerWithDeps($this->router, $deps);
        ContentRouter::registerWithDeps($this->router, $deps);
    }

    /**
     * Dispatches one normalized request through the isolated public route map.
     *
     * @param Request $request Normalized routing request payload.
     * @return Response Dispatch result indicating match state and params.
     */
    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }
}
