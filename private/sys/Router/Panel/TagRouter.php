<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/TagRouter.php
 * Panel tag-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;

/**
 * Registers tag-management routes for the panel runtime.
 */
final class TagRouter
{
    /**
     * Registers tag routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving tag routes.
     * @param PanelRouteDeps $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelRouteDeps $deps): void
    {
        if (!$deps->tagEnabled) {
            return;
        }

        $base = '/tag';

        $router->add('GET', $base, static function () use ($deps): void {
            ($deps->panelTagListController)()->tagList();
        });

        $router->add('GET', $base . '/edit', static function () use ($deps): void {
            ($deps->panelTagEditController)()->tagEdit(null);
        });

        $router->add('GET', $base . '/edit/{id}', static function (array $params) use ($deps): void {
            $id = RouteValidator::intOrNotFound($deps->input, $params['id'] ?? null, 1, $deps->renderNotFound);
            if ($id === null) {
                return;
            }

            ($deps->panelTagEditController)()->tagEdit($id);
        });

        $router->add('POST', $base . '/save', static function () use ($deps): void {
            ($deps->panelTagEditController)()->tagSave($_POST, $_FILES);
        });

        $router->add('POST', $base . '/delete', static function () use ($deps): void {
            ($deps->panelTagEditController)()->tagDelete($_POST);
        });

        SetRouter::register(
            $router,
            $base,
            fn() => ($deps->panelTagListController)()->tagSetList(),
            fn() => ($deps->panelTagEditController)()->tagSetEdit(null),
            fn(int $id) => ($deps->panelTagEditController)()->tagSetEdit($id),
            fn() => ($deps->panelTagEditController)()->tagSetSave($_POST),
            fn() => ($deps->panelTagEditController)()->tagSetDelete($_POST),
            $deps->input,
            $deps->renderNotFound
        );
    }
}
