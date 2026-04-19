<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelGroupRouteRegistrar.php
 * Panel group-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers group-management routes for the panel runtime.
 */
final class PanelGroupRouteRegistrar
{
    /**
     * Registers the panel group route family.
     *
     * @param Router $router Mutable router receiving group routes.
     * @param callable(): object $panelGroupController Lazy group controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelGroupController,
        InputSanitizer $input
    ): void {
        $router->add('GET', '/group', static function () use ($panelGroupController): void {
            $panelGroupController()->groupList();
        });

        $router->add('GET', '/group/edit', static function () use ($panelGroupController): void {
            $panelGroupController()->groupEdit(null);
        });

        $router->add('GET', '/group/edit/{id}', static function (array $params) use ($panelGroupController, $input): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                http_response_code(404);
                echo 'Not Found';
                return;
            }

            $panelGroupController()->groupEdit($id);
        });

        $router->add('POST', '/group/save', static function () use ($panelGroupController): void {
            $panelGroupController()->groupSave($_POST, $_FILES);
        });

        $router->add('POST', '/group/delete', static function () use ($panelGroupController): void {
            $panelGroupController()->groupDelete($_POST);
        });
    }
}
