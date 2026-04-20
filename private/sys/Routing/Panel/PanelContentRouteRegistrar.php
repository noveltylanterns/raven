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
 * Registers page/content routes for the panel runtime.
 */
final class PanelContentRouteRegistrar
{
    /**
     * Registers the panel content route family.
     *
     * @param Router $router Mutable router receiving content routes.
     * @param callable(): object $panelContentController Lazy content controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelContentController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/page', static function () use ($panelContentController): void {
            $panelContentController()->pageList();
        });

        $router->add('GET', '/page/edit', static function () use ($panelContentController): void {
            $panelContentController()->pageEdit(null);
        });

        $router->add('GET', '/page/edit/{id}', static function (array $params) use ($panelContentController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelContentController()->pageEdit($id);
        });

        $router->add('POST', '/page/save', static function () use ($panelContentController): void {
            $panelContentController()->pageSave($_POST);
        });

        $router->add('POST', '/page/gallery/upload', static function () use ($panelContentController): void {
            $panelContentController()->pageGalleryUpload($_POST, $_FILES);
        });

        $router->add('POST', '/page/gallery/delete', static function () use ($panelContentController): void {
            $panelContentController()->pageGalleryDelete($_POST);
        });

        $router->add('POST', '/page/delete', static function () use ($panelContentController): void {
            $panelContentController()->pageDelete($_POST);
        });
    }
}
