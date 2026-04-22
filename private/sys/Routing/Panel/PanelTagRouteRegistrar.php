<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelTagRouteRegistrar.php
 * Panel tag-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers tag-management routes for the panel runtime.
 */
final class PanelTagRouteRegistrar
{
    /**
     * Registers the panel tag route family when tag support is enabled.
     *
     * @param Router $router Mutable router receiving tag routes.
     * @param callable(): object $panelTagController Lazy tag controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param bool $tagEnabled Whether tag routes are enabled for this request.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelTagController,
        InputSanitizer $input,
        bool $tagEnabled,
        callable $renderNotFound
    ): void {
        if (!$tagEnabled) {
            return;
        }

        $router->add('GET', '/tag', static function () use ($panelTagController): void {
            $panelTagController()->tagList();
        });

        $router->add('GET', '/tag/edit', static function () use ($panelTagController): void {
            $panelTagController()->tagEdit(null);
        });

        $router->add('GET', '/tag/edit/{id}', static function (array $params) use ($panelTagController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelTagController()->tagEdit($id);
        });

        $router->add('POST', '/tag/save', static function () use ($panelTagController): void {
            $panelTagController()->tagSave($_POST, $_FILES);
        });

        $router->add('POST', '/tag/delete', static function () use ($panelTagController): void {
            $panelTagController()->tagDelete($_POST);
        });

        $router->add('GET', '/tag/set', static function () use ($panelTagController): void {
            $panelTagController()->tagSetList();
        });

        $router->add('GET', '/tag/set/edit', static function () use ($panelTagController): void {
            $panelTagController()->tagSetEdit(null);
        });

        $router->add('GET', '/tag/set/edit/{id}', static function (array $params) use ($panelTagController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 0);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelTagController()->tagSetEdit($id);
        });

        $router->add('POST', '/tag/set/save', static function () use ($panelTagController): void {
            $panelTagController()->tagSetSave($_POST);
        });

        $router->add('POST', '/tag/set/delete', static function () use ($panelTagController): void {
            $panelTagController()->tagSetDelete($_POST);
        });
    }
}
