<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/UserRouter.php
 * Panel user-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers user-management routes for the panel runtime.
 */
final class UserRouter
{
    /**
     * Registers user routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving user routes.
     * @param PanelRouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelRouteDeps $deps): void
    {
        self::register(
            $router,
            $deps->panelUserListController,
            $deps->panelUserEditController,
            $deps->input,
            $deps->renderNotFound
        );
    }

    /**
     * Registers the panel user route family.
     *
     * @param RouteHandler $router Mutable router receiving user routes.
     * @param callable(): object $panelUserListController Lazy user list controller factory for GET /user and GET /user/invites.
     * @param callable(): object $panelUserEditController Lazy user edit controller factory for create/edit/save/delete and invite write routes.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        callable $panelUserListController,
        callable $panelUserEditController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/user', static function () use ($panelUserListController): void {
            $panelUserListController()->userList();
        });

        $router->add('GET', '/user/edit', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userEdit(null);
        });

        $router->add('GET', '/user/edit/{id}', static function (array $params) use ($panelUserEditController, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
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

        $router->add('POST', '/user/invites/create', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userInvitesCreate($_POST);
        });

        $router->add('POST', '/user/invites/generate', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userInvitesGenerate($_POST);
        });

        $router->add('POST', '/user/invites/delete', static function () use ($panelUserEditController): void {
            $panelUserEditController()->userInvitesDelete($_POST);
        });
    }
}
