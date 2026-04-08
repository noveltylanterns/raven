<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelRedirectRouteRegistrar.php
 * Panel redirect-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Lib\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers redirect-management routes for the panel runtime.
 */
final class PanelRedirectRouteRegistrar
{
    /**
     * Registers the panel redirect route family.
     *
     * @param Router $router Mutable router receiving redirect routes.
     * @param callable(): object $panelRedirectController Lazy redirect controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelRedirectController,
        InputSanitizer $input
    ): void {
        $router->add('GET', '/redirect', static function () use ($panelRedirectController): void {
            $panelRedirectController()->redirectList();
        });

        $router->add('GET', '/redirect/edit', static function () use ($panelRedirectController): void {
            $panelRedirectController()->redirectEdit(null);
        });

        $router->add('GET', '/redirect/edit/{id}', static function (array $params) use ($panelRedirectController, $input): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                http_response_code(404);
                echo 'Not Found';
                return;
            }

            $panelRedirectController()->redirectEdit($id);
        });

        $router->add('POST', '/redirect/save', static function () use ($panelRedirectController): void {
            $panelRedirectController()->redirectSave($_POST);
        });

        $router->add('POST', '/redirect/delete', static function () use ($panelRedirectController): void {
            $panelRedirectController()->redirectDelete($_POST);
        });
    }
}
