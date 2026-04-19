<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelUserRouteRegistrar.php
 * Panel user-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers user-management routes for the panel runtime.
 */
final class PanelUserRouteRegistrar
{
    /**
     * Registers the panel user route family.
     *
     * @param Router $router Mutable router receiving user routes.
     * @param callable(): object $panelUserController Lazy user controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelUserController,
        InputSanitizer $input
    ): void {
        $router->add('GET', '/user', static function () use ($panelUserController): void {
            $panelUserController()->userList();
        });

        $router->add('GET', '/user/edit', static function () use ($panelUserController): void {
            $panelUserController()->userEdit(null);
        });

        $router->add('GET', '/user/edit/{id}', static function (array $params) use ($panelUserController, $input): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                http_response_code(404);
                echo 'Not Found';
                return;
            }

            $panelUserController()->userEdit($id);
        });

        $router->add('POST', '/user/save', static function () use ($panelUserController): void {
            $panelUserController()->userSave($_POST, $_FILES);
        });

        $router->add('POST', '/user/delete', static function () use ($panelUserController): void {
            $panelUserController()->userDelete($_POST);
        });

        $router->add('GET', '/user/invites', static function () use ($panelUserController): void {
            $panelUserController()->userInvites();
        });

        $router->add('POST', '/user/invites/create', static function () use ($panelUserController): void {
            $panelUserController()->userInvitesCreate($_POST);
        });

        $router->add('POST', '/user/invites/generate', static function () use ($panelUserController): void {
            $panelUserController()->userInvitesGenerate($_POST);
        });

        $router->add('POST', '/user/invites/delete', static function () use ($panelUserController): void {
            $panelUserController()->userInvitesDelete($_POST);
        });
    }
}
