<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/PageRouter.php
 * Panel content-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers page routes for the panel runtime.
 */
final class PageRouter
{
    /**
     * Registers page routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving page routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        self::register(
            $router,
            $deps->panelPageListController,
            $deps->panelPageEditController,
            $deps->input,
            $deps->renderNotFound
        );
    }

    /**
     * Registers the panel page route family.
     *
     * @param RouteHandler $router Mutable router receiving page routes.
     * @param callable(): object $panelPageListController Lazy page list controller factory for GET /page.
     * @param callable(): object $panelPageEditController Lazy page edit controller factory for create/edit/save/gallery/delete routes.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        callable $panelPageListController,
        callable $panelPageEditController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
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
