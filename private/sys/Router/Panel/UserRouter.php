<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/UserRouter.php
 * Panel user-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers user-management routes for the panel runtime.
 */
final class UserRouter
{
    /**
     * Registers the panel user route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving user routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelUserListController = $deps->panelUserListController;
        $panelUserEditController = $deps->panelUserEditController;
        $panelUserInviteController = $deps->panelUserInviteController;
        $input = $deps->input;
        $renderNotFound = $deps->renderNotFound;

        $router->add('GET', '/user', static function () use ($panelUserListController): void {
            $panelUserListController()->userList();
        });

        $router->add('GET', '/user/edit', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userEdit(null);
        });

        $router->add('GET', '/user/edit/{id}', static function (array $params) use ($panelUserEditController, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            // Validation helper already rendered not-found for invalid ids.
            if ($id === null) {
                return;
            }

            $panelUserEditController()->userEdit($id);
        });

        $router->add('POST', '/user/save', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userSave($_POST, $_FILES);
        });

        $router->add('POST', '/user/delete', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userDelete($_POST);
        });

        $router->add('GET', '/user/invites', static function () use ($panelUserListController): void {
            $panelUserListController()->userInvites();
        });

        $router->add('POST', '/user/invites/create', static function () use ($panelUserInviteController): void {
            $panelUserInviteController()->userInvitesCreate($_POST);
        });

        $router->add('POST', '/user/invites/generate', static function () use ($panelUserInviteController): void {
            $panelUserInviteController()->userInvitesGenerate($_POST);
        });

        $router->add('POST', '/user/invites/delete', static function () use ($panelUserInviteController): void {
            $panelUserInviteController()->userInvitesDelete($_POST);
        });
    }
}
