<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/PanelRouter.php
 * Panel-route orchestration over one isolated router instance.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Lib\Extension\Panel\Routes as PanelRoutes;
use Raven\Core\Router\RouteRequest;
use Raven\Core\Router\RouteResponse;
use Raven\Core\Router\RouteHandler;

/**
 * Owns panel-route registration order and dispatch lifecycle.
 */
final class PanelRouter
{
    private RouteHandler $router;

    /**
     * Creates one isolated router instance for panel route orchestration.
     */
    public function __construct()
    {
        $this->router = new RouteHandler();
    }

    /**
     * Registers the full panel route map in canonical registration order.
     *
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public function register(PanelPayload $deps): void
    {
        AuthRouter::registerWithDeps($this->router, $deps);
        DashboardRouter::registerWithDeps($this->router, $deps);
        PageRouter::registerWithDeps($this->router, $deps);
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
        ThemeRouter::registerWithDeps($this->router, $deps);
        ExtensionRouter::registerWithDeps($this->router, $deps);
        PanelRoutes::register(
            $this->router,
            $deps->rvn,
            $deps->enabledExtensions,
            $deps->enabledExtensionManifests,
            $deps->extensionPermissionCatalog,
            $deps->internalPath,
            $deps->renderPublicNotFound
        );
    }

    /**
     * Dispatches one normalized request through the isolated panel route map.
     *
     * @param RouteRequest  Normalized routing request payload.
     * @return RouteResponse Dispatch result indicating match state and params.
     */
    public function dispatch(RouteRequest $request): RouteResponse
    {
        return $this->router->dispatch($request);
    }
}
