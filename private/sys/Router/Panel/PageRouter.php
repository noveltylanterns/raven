<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/PageRouter.php
 * Panel content-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers page routes for the panel runtime.
 */
final class PageRouter
{
    /**
     * Registers the panel page route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving page routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelPageListController = $deps->panelPageListController;
        $panelPageEditController = $deps->panelPageEditController;
        $input = $deps->input;
        $renderNotFound = $deps->renderNotFound;

        $router->add('GET', '/page', static function () use ($panelPageListController): void {
            $panelPageListController()->pageList();
        });

        $router->add('GET', '/page/edit', static function () use ($panelPageEditController): void {
            $panelPageEditController()->pageEdit(null);
        });

        $router->add('GET', '/page/edit/{id}', static function (array $params) use ($panelPageEditController, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            if ($id === null) {
                return;
            }

            $panelPageEditController()->pageEdit($id);
        });

        $router->add('POST', '/page/save', static function () use ($panelPageEditController): void {
            $panelPageEditController()->pageSave($_POST);
        });

        $router->add('POST', '/page/gallery/upload', static function () use ($panelPageEditController): void {
            $panelPageEditController()->pageGalleryUpload($_POST, $_FILES);
        });

        $router->add('POST', '/page/gallery/delete', static function () use ($panelPageEditController): void {
            $panelPageEditController()->pageGalleryDelete($_POST);
        });

        $router->add('POST', '/page/delete', static function () use ($panelPageEditController): void {
            $panelPageEditController()->pageDelete($_POST);
        });
    }
}
