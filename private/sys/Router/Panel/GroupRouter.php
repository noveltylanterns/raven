<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/GroupRouter.php
 * Panel group-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;

/**
 * Registers group-management routes for the panel runtime.
 */
final class GroupRouter
{
    /**
     * Registers the panel group route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving group routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelGroupListController = $deps->panelGroupListController;
        $panelGroupEditController = $deps->panelGroupEditController;
        $input = $deps->input;
        $renderNotFound = $deps->renderNotFound;

        $router->add('GET', '/group', static function () use ($panelGroupListController): void {
            $panelGroupListController()->groupList();
        });

        $router->add('GET', '/group/edit', static function () use ($panelGroupEditController): void {
            $panelGroupEditController()->groupEdit(null);
        });

        $router->add('GET', '/group/edit/{id}', static function (array $params) use ($panelGroupEditController, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            if ($id === null) {
                return;
            }

            $panelGroupEditController()->groupEdit($id);
        });

        $router->add('POST', '/group/save', static function () use ($panelGroupEditController): void {
            $panelGroupEditController()->groupSave($_POST, $_FILES);
        });

        $router->add('POST', '/group/delete', static function () use ($panelGroupEditController): void {
            $panelGroupEditController()->groupDelete($_POST);
        });
    }
}
