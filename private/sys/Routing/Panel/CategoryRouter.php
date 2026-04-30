<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/CategoryRouter.php
 * Panel category-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers category-management routes for the panel runtime.
 *
 * Delegates the full 10-route CRUD and set-management layout to
 * TaxonomyCrudRouter, supplying category-specific controller closures.
 */
final class CategoryRouter
{
    /**
     * Registers category routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving category routes.
     * @param RouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        TaxonomyCrudRouter::register(
            $router,
            'category',
            fn() => ($deps->panelCategoryListController)()->categoryList(),
            fn() => ($deps->panelCategoryListController)()->categorySetList(),
            fn() => ($deps->panelCategoryEditController)()->categoryEdit(null),
            fn(int $id) => ($deps->panelCategoryEditController)()->categoryEdit($id),
            fn() => ($deps->panelCategoryEditController)()->categorySave($_POST, $_FILES),
            fn() => ($deps->panelCategoryEditController)()->categoryDelete($_POST),
            fn() => ($deps->panelCategoryEditController)()->categorySetEdit(null),
            fn(int $id) => ($deps->panelCategoryEditController)()->categorySetEdit($id),
            fn() => ($deps->panelCategoryEditController)()->categorySetSave($_POST),
            fn() => ($deps->panelCategoryEditController)()->categorySetDelete($_POST),
            $deps->input,
            $deps->categoryEnabled,
            $deps->renderNotFound
        );
    }
}
