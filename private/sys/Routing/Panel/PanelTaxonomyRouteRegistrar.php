<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelTaxonomyRouteRegistrar.php
 * Panel taxonomy-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Routing\Router;
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
     * @param callable(): object $panelTaxonomyController Lazy taxonomy controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param bool $categoryEnabled Whether category routes are enabled for this request.
     * @param bool $tagEnabled Whether tag routes are enabled for this request.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelTaxonomyController,
        InputSanitizer $input,
        bool $categoryEnabled,
        bool $tagEnabled
    ): void {
        $router->add('GET', '/channel', static function () use ($panelTaxonomyController): void {
            $panelTaxonomyController()->channelList();
        });

        $router->add('GET', '/channel/edit', static function () use ($panelTaxonomyController): void {
            $panelTaxonomyController()->channelEdit(null);
        });

        $router->add('GET', '/channel/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                http_response_code(404);
                echo 'Not Found';
                return;
            }

            $panelTaxonomyController()->channelEdit($id);
        });

        $router->add('POST', '/channel/save', static function () use ($panelTaxonomyController): void {
            $panelTaxonomyController()->channelSave($_POST, $_FILES);
        });

        $router->add('POST', '/channel/delete', static function () use ($panelTaxonomyController): void {
            $panelTaxonomyController()->channelDelete($_POST);
        });

        if ($categoryEnabled) {
            $router->add('GET', '/category', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categoryList();
            });

            $router->add('GET', '/category/edit', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categoryEdit(null);
            });

            $router->add('GET', '/category/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input): void {
                $id = $input->int($params['id'] ?? null, 1);

                if ($id === null) {
                    http_response_code(404);
                    echo 'Not Found';
                    return;
                }

                $panelTaxonomyController()->categoryEdit($id);
            });

            $router->add('POST', '/category/save', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categorySave($_POST, $_FILES);
            });

            $router->add('POST', '/category/delete', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categoryDelete($_POST);
            });

            $router->add('GET', '/category/set', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categorySetList();
            });

            $router->add('GET', '/category/set/edit', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categorySetEdit(null);
            });

            $router->add('GET', '/category/set/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input): void {
                $id = $input->int($params['id'] ?? null, 0);

                if ($id === null) {
                    http_response_code(404);
                    echo 'Not Found';
                    return;
                }

                $panelTaxonomyController()->categorySetEdit($id);
            });

            $router->add('POST', '/category/set/save', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categorySetSave($_POST);
            });

            $router->add('POST', '/category/set/delete', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->categorySetDelete($_POST);
            });
        }

        if ($tagEnabled) {
            $router->add('GET', '/tag', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagList();
            });

            $router->add('GET', '/tag/edit', static function () use ($panelTaxonomyController): void {
                $panelTaxonomyController()->tagEdit(null);
            });

            $router->add('GET', '/tag/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input): void {
                $id = $input->int($params['id'] ?? null, 1);

                if ($id === null) {
                    http_response_code(404);
                    echo 'Not Found';
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

            $router->add('GET', '/tag/set/edit/{id}', static function (array $params) use ($panelTaxonomyController, $input): void {
                $id = $input->int($params['id'] ?? null, 0);

                if ($id === null) {
                    http_response_code(404);
                    echo 'Not Found';
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
