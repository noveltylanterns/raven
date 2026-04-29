<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Panel/ChannelRouter.php
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
final class ChannelRouter
{
    /**
     * Registers the panel channel route family.
     *
     * @param Router $router Mutable router receiving channel routes.
     * @param callable(): object $panelChannelListController Lazy channel list controller factory for GET /channel.
     * @param callable(): object $panelChannelEditController Lazy channel edit controller factory for create/edit/save/delete routes.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param callable(): void $renderNotFound Renders a 404 response when a route param is invalid.
     * @return void
     */
    public static function register(
        Router $router,
        callable $panelChannelListController,
        callable $panelChannelEditController,
        InputSanitizer $input,
        callable $renderNotFound
    ): void {
        $router->add('GET', '/channel', static function () use ($panelChannelListController): void {
            $panelChannelListController()->channelList();
        });

        $router->add('GET', '/channel/edit', static function () use ($panelChannelEditController): void {
            $panelChannelEditController()->channelEdit(null);
        });

        $router->add('GET', '/channel/edit/{id}', static function (array $params) use ($panelChannelEditController, $input, $renderNotFound): void {
            $id = $input->int($params['id'] ?? null, 1);

            if ($id === null) {
                $renderNotFound();
                return;
            }

            $panelChannelEditController()->channelEdit($id);
        });

        $router->add('POST', '/channel/save', static function () use ($panelChannelEditController): void {
            $panelChannelEditController()->channelSave($_POST, $_FILES);
        });

        $router->add('POST', '/channel/delete', static function () use ($panelChannelEditController): void {
            $panelChannelEditController()->channelDelete($_POST);
        });
    }
}
