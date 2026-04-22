<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelCategoryRouteRegistrar.php
 * Panel category-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers category-management routes for the panel runtime.
 */
final class PanelCategoryRouteRegistrar
{
    /**
     * Registers the panel category route family when category support is enabled.
     *
     * @param Router $router Mutable router receiving category routes.
     * @param callable(): object $panelCategoryController Lazy category controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param bool $categoryEnabled Whether category routes are enabled for this request.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelCategoryController,
        InputSanitizer $input,
        bool $categoryEnabled,
        callable $renderNotFound
    ): void {
        if (!$categoryEnabled) {
            return;
        }

        $router->add('GET', '/category', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categoryList();
        });

        $router->add('GET', '/category/edit', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categoryEdit(null);
        });

        $router->add('GET', '/category/edit/{id}', static function (array $params) use ($panelCategoryController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelCategoryController()->categoryEdit($id);
        });

        $router->add('POST', '/category/save', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categorySave($_POST, $_FILES);
        });

        $router->add('POST', '/category/delete', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categoryDelete($_POST);
        });

        $router->add('GET', '/category/set', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categorySetList();
        });

        $router->add('GET', '/category/set/edit', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categorySetEdit(null);
        });

        $router->add('GET', '/category/set/edit/{id}', static function (array $params) use ($panelCategoryController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 0);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelCategoryController()->categorySetEdit($id);
        });

        $router->add('POST', '/category/set/save', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categorySetSave($_POST);
        });

        $router->add('POST', '/category/set/delete', static function () use ($panelCategoryController): void {
            $panelCategoryController()->categorySetDelete($_POST);
        });
    }
}
