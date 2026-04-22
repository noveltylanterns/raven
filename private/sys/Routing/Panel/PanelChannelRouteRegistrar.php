<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/PanelChannelRouteRegistrar.php
 * Panel channel-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Panel;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers channel-management routes for the panel runtime.
 */
final class PanelChannelRouteRegistrar
{
    /**
     * Registers the panel channel route family.
     *
     * @param Router $router Mutable router receiving channel routes.
     * @param callable(): object $panelChannelController Lazy channel controller factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelChannelController,
        InputSanitizer $input,
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
    }
}
