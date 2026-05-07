<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/ChannelRouter.php
 * Panel channel-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;

/**
 * Registers channel-management routes for the panel runtime.
 */
final class ChannelRouter
{
    /**
     * Registers the panel channel route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving channel routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelChannelListController = $deps->panelChannelListController;
        $panelChannelEditController = $deps->panelChannelEditController;
        $input = $deps->input;
        $renderNotFound = $deps->renderNotFound;

        $router->add('GET', '/channel', static function () use ($panelChannelListController): void {
            $panelChannelListController()->channelList();
        });

        $router->add('GET', '/channel/edit', static function () use ($panelChannelEditController): void {
            $panelChannelEditController()->channelEdit(null);
        });

        $router->add('GET', '/channel/edit/{id}', static function (array $params) use ($panelChannelEditController, $input, $renderNotFound): void {
            $id = RouteValidator::intOrNotFound($input, $params['id'] ?? null, 1, $renderNotFound);
            if ($id === null) {
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
