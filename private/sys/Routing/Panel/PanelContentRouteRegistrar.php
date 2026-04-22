<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelContentRouteRegistrar.php
 * Panel content-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers page routes for the panel runtime.
 */
final class PanelContentRouteRegistrar
{
    /**
     * Registers the panel page route family.
     *
     * @param Router $router Mutable router receiving page routes.
     * @param callable(): object $panelPageController Lazy page controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelPageController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/page', static function () use ($panelPageController): void {
            $panelPageController()->pageList();
        });

        $router->add('GET', '/page/edit', static function () use ($panelPageController): void {
            $panelPageController()->pageEdit(null);
        });

        $router->add('GET', '/page/edit/{id}', static function (array $params) use ($panelPageController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelPageController()->pageEdit($id);
        });

        $router->add('POST', '/page/save', static function () use ($panelPageController): void {
            $panelPageController()->pageSave($_POST);
        });

        $router->add('POST', '/page/gallery/upload', static function () use ($panelPageController): void {
            $panelPageController()->pageGalleryUpload($_POST, $_FILES);
        });

        $router->add('POST', '/page/gallery/delete', static function () use ($panelPageController): void {
            $panelPageController()->pageGalleryDelete($_POST);
        });

        $router->add('POST', '/page/delete', static function () use ($panelPageController): void {
            $panelPageController()->pageDelete($_POST);
        });
    }
}
