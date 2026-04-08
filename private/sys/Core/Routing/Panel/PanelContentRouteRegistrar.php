<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Routing/Panel/PanelContentRouteRegistrar.php
 * Panel content-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Routing\Router;
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
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelContentController,
        InputSanitizer $input
    ): void {
        $router->add('GET', '/page', static function () use ($panelContentController): void {
            $panelContentController()->pageList();
        });

        $router->add('GET', '/page/edit', static function () use ($panelContentController): void {
            $panelContentController()->pageEdit(null);
        });

        $router->add('GET', '/page/edit/{id}', static function (array $params) use ($panelContentController, $input): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                http_response_code(404);
                echo 'Not Found';
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
