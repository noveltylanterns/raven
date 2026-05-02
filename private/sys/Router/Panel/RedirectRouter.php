<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/RedirectRouter.php
 * Panel redirect-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers redirect-management routes for the panel runtime.
 */
final class RedirectRouter
{
    /**
     * Registers redirect routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving redirect routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        self::register(
            $router,
            $deps->panelRedirectListController,
            $deps->panelRedirectEditController,
            $deps->input,
            $deps->renderNotFound
        );
    }

    /**
     * Registers the panel redirect route family.
     *
     * @param RouteHandler $router Mutable router receiving redirect routes.
     * @param callable(): object $panelRedirectListController Lazy redirect list controller factory for GET /redirect.
     * @param callable(): object $panelRedirectEditController Lazy redirect edit controller factory for create/edit/save/delete routes.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        RouteHandler $router,
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
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            if ($id === null) {
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
