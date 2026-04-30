<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/TagRouter.php
 * Panel tag-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;

/**
 * Registers tag-management routes for the panel runtime.
 *
 * Delegates the full 10-route CRUD and set-management layout to
 * TaxonomyCrudRouter, supplying tag-specific controller closures.
 */
final class TagRouter
{
    /**
     * Registers tag routes from one shared dependency payload.
     *
     * @param Router $router Mutable router receiving tag routes.
     * @param RouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(Router $router, RouteDeps $deps): void
    {
        TaxonomyCrudRouter::register(
            $router,
            'tag',
            fn() => ($deps->panelTagListController)()->tagList(),
            fn() => ($deps->panelTagListController)()->tagSetList(),
            fn() => ($deps->panelTagEditController)()->tagEdit(null),
            fn(int $id) => ($deps->panelTagEditController)()->tagEdit($id),
            fn() => ($deps->panelTagEditController)()->tagSave($_POST, $_FILES),
            fn() => ($deps->panelTagEditController)()->tagDelete($_POST),
            fn() => ($deps->panelTagEditController)()->tagSetEdit(null),
            fn(int $id) => ($deps->panelTagEditController)()->tagSetEdit($id),
            fn() => ($deps->panelTagEditController)()->tagSetSave($_POST),
            fn() => ($deps->panelTagEditController)()->tagSetDelete($_POST),
            $deps->input,
            $deps->tagEnabled,
            $deps->renderNotFound
        );
    }
}
