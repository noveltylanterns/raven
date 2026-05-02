<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/CategoryRouter.php
 * Panel category-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;

/**
 * Registers category-management routes for the panel runtime.
 */
final class CategoryRouter
{
    /**
     * Registers category routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving category routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        if (!$deps->categoryEnabled) {
            return;
        }

        $base = '/category';

        $router->add('GET', $base, static function () use ($deps): void {
            ($deps->panelCategoryListController)()->categoryList();
        });

        $router->add('GET', $base . '/edit', static function () use ($deps): void {
            ($deps->panelCategoryEditController)()->categoryEdit(null);
        });

        $router->add('GET', $base . '/edit/{id}', static function (array $params) use ($deps): void {
            $id = RouteValidator::intOrNotFound($deps->input, $params['id'] ?? null, 1, $deps->renderNotFound);
            if ($id === null) {
                return;
            }

            ($deps->panelCategoryEditController)()->categoryEdit($id);
        });

        $router->add('POST', $base . '/save', static function () use ($deps): void {
            ($deps->panelCategoryEditController)()->categorySave($_POST, $_FILES);
        });

        $router->add('POST', $base . '/delete', static function () use ($deps): void {
            ($deps->panelCategoryEditController)()->categoryDelete($_POST);
        });

        SetRouter::register(
            $router,
            $base,
            fn() => ($deps->panelCategoryListController)()->categorySetList(),
            fn() => ($deps->panelCategoryEditController)()->categorySetEdit(null),
            fn(int $id) => ($deps->panelCategoryEditController)()->categorySetEdit($id),
            fn() => ($deps->panelCategoryEditController)()->categorySetSave($_POST),
            fn() => ($deps->panelCategoryEditController)()->categorySetDelete($_POST),
            $deps->input,
            $deps->renderNotFound
        );
    }
}
