<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelTaxonomyRouteRegistrar.php
 * Panel taxonomy-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers channel/category/tag route families for the panel runtime.
 */
final class PanelTaxonomyRouteRegistrar
{
    /**
     * Registers the panel taxonomy route family.
     *
     * @param Router $router Mutable router receiving taxonomy routes.
     * @param callable(): object $panelChannelController Lazy channel controller factory.
     * @param callable(): object $panelCategoryController Lazy category controller factory.
     * @param callable(): object $panelTaxonomyController Lazy taxonomy controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param bool $categoryEnabled Whether category routes are enabled for this request.
     * @param bool $tagEnabled Whether tag routes are enabled for this request.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelChannelController,
        callable $panelCategoryController,
        callable $panelTaxonomyController,
        InputSanitizer $input,
        bool $categoryEnabled,
        bool $tagEnabled,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/channel', static function () use ($panelChannelController): void {
            $panelChannelController()->channelList();
        });

        $router->add('GET', '/channel/edit', static function () use ($panelChannelController): void {
            $panelChannelController()->channelEdit(null);
        });

        $router->add('GET', '/channel/edit/{id}', static function (array $params) use ($panelChannelController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelChannelController()->channelEdit($id);
        });

        $router->add('POST', '/channel/save', static function () use ($panelChannelController): void {
            $panelChannelController()->channelSave($_POST, $_FILES);
        });

        $router->add('POST', '/channel/delete', static function () use ($panelChannelController): void {
            $panelChannelController()->channelDelete($_POST);
        });

        if ($categoryEnabled) {
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

        if ($tagEnabled) {
            $router->add('GET', '/tag', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagList();
            });

            $router->add('GET', '/tag/edit', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagEdit(null);
            });

            $router->add('GET', '/tag/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input, $renderNotFound): void {
                $id = $input->int($params['id'] ?? null, 1);

                if ($id === null) {
                    $renderNotFound();
                    return;
                }

                $panelTaxonomyController()->tagEdit($id);
            });

            $router->add('POST', '/tag/save', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagSave($_POST, $_FILES);
            });

            $router->add('POST', '/tag/delete', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagDelete($_POST);
            });

            $router->add('GET', '/tag/set', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagSetList();
            });

            $router->add('GET', '/tag/set/edit', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagSetEdit(null);
            });

            $router->add('GET', '/tag/set/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input, $renderNotFound): void {
                $id = $input->int($params['id'] ?? null, 0);

                if ($id === null) {
                    $renderNotFound();
                    return;
                }

                $panelTaxonomyController()->tagSetEdit($id);
            });

            $router->add('POST', '/tag/set/save', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagSetSave($_POST);
            });

            $router->add('POST', '/tag/set/delete', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagSetDelete($_POST);
            });
        }
    }
}
