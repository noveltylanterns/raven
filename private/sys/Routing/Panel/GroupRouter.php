<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/GroupRouter.php
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
final class GroupRouter
{
    /**
     * Registers the panel group route family.
     *
     * @param Router $router Mutable router receiving group routes.
     * @param callable(): object $panelGroupListController Lazy group list controller factory for GET /group.
     * @param callable(): object $panelGroupEditController Lazy group edit controller factory for create/edit/save/delete routes.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelGroupListController,
        callable $panelGroupEditController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/group', static function () use ($panelGroupListController): void {
            $panelGroupListController()->groupList();
        });

        $router->add('GET', '/group/edit', static function () use ($panelGroupEditController): void {
            $panelGroupEditController()->groupEdit(null);
        });

        $router->add('GET', '/group/edit/{id}', static function (array $params) use ($panelGroupEditController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
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
