<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelRouter.php
 * Panel-route orchestration over one isolated router instance.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Request;
use Raven\Core\Routing\Response;
use Raven\Core\Routing\Router;

/**
 * Owns panel-route registration order and dispatch lifecycle.
 */
final class PanelRouter
{
    private Router $router;

    /**
     * Creates one isolated router instance for panel route orchestration.
     */
    public function __construct()
    {
        $this->router = new Router();
    }

    /**
     * Registers the full panel route map in canonical registration order.
     *
     * @param RouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public function register(RouteDeps $deps): void
    {
        AuthRouter::registerWithDeps($this->router, $deps);
        DashboardRouter::registerWithDeps($this->router, $deps);
        ContentRouter::registerWithDeps($this->router, $deps);
        ChannelRouter::registerWithDeps($this->router, $deps);
        CategoryRouter::registerWithDeps($this->router, $deps);
        TagRouter::registerWithDeps($this->router, $deps);
        RedirectRouter::registerWithDeps($this->router, $deps);
        UserRouter::registerWithDeps($this->router, $deps);
        GroupRouter::registerWithDeps($this->router, $deps);
        LogRouter::registerWithDeps($this->router, $deps);
        RoutingRouter::registerWithDeps($this->router, $deps);
        UpdateRouter::registerWithDeps($this->router, $deps);
        PreferencesRouter::registerWithDeps($this->router, $deps);
        ConfigRouter::registerWithDeps($this->router, $deps);
        SystemRouter::registerWithDeps($this->router, $deps);
        ExtensionRouter::registerWithDeps($this->router, $deps);
    }

    /**
     * Dispatches one normalized request through the isolated panel route map.
     *
     * @param Request $request Normalized routing request payload.
     * @return Response Dispatch result indicating match state and params.
     */
    public function dispatch(Request $request): Response
    {
        return $this->router->dispatch($request);
    }
}
