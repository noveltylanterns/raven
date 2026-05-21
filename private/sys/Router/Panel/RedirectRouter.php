<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/RedirectRouter.php
 * Panel redirect-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers redirect-management routes for the panel runtime.
 */
final class RedirectRouter
{
    /**
     * Registers the panel redirect route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving redirect routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelRedirectListController = $deps->panelRedirectListController;
        $panelRedirectEditController = $deps->panelRedirectEditController;
        $input = $deps->input;
        $renderNotFound = $deps->renderNotFound;

        $router->add('GET', '/redirect', static function () use ($panelRedirectListController): void {
            $panelRedirectListController()->redirectList();
        });

        $router->add('GET', '/redirect/edit', static function () use ($panelRedirectEditController): void {
            $panelRedirectEditController()->redirectEdit(null);
        });

        $router->add('GET', '/redirect/edit/{id}', static function (array $params) use ($panelRedirectEditController, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            if ($id === null) {
                return;
            }

            $panelRedirectEditController()->redirectEdit($id);
        });

        $router->add('POST', '/redirect/save', static function () use ($panelRedirectEditController): void {
            $panelRedirectEditController()->redirectSave($_POST);
        });

        $router->add('POST', '/redirect/delete', static function () use ($panelRedirectEditController): void {
            $panelRedirectEditController()->redirectDelete($_POST);
        });
    }
}
