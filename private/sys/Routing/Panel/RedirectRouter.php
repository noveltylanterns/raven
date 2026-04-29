<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/RedirectRouter.php
 * Panel redirect-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers redirect-management routes for the panel runtime.
 */
final class RedirectRouter
{
    /**
     * Registers the panel redirect route family.
     *
     * @param Router $router Mutable router receiving redirect routes.
     * @param callable(): object $panelRedirectListController Lazy redirect list controller factory for GET /redirect.
     * @param callable(): object $panelRedirectEditController Lazy redirect edit controller factory for create/edit/save/delete routes.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelRedirectListController,
        callable $panelRedirectEditController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/redirect', static function () use ($panelRedirectListController): void {
            $panelRedirectListController()->redirectList();
        });

        $router->add('GET', '/redirect/edit', static function () use ($panelRedirectEditController): void {
            $panelRedirectEditController()->redirectEdit(null);
        });

        $router->add('GET', '/redirect/edit/{id}', static function (array $params) use ($panelRedirectEditController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelRedirectEditController()->redirectEdit($id);
        });

        $router->add('POST', '/redirect/save', static function () use ($panelRedirectEditController): void {
            $panelRedirectEditController()->redirectSave($_POST);
        });

        $router->add('POST', '/redirect/delete', static function () use ($panelRedirectEditController): void {
            $panelRedirectEditController()->redirectDelete($_POST);
        });
    }
}
