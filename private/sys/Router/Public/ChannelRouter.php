<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/ChannelRouter.php
 * Public channel-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers single-segment public channel routes.
 */
final class ChannelRouter
{
    /**
     * Registers the public channel route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving channel routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        $publicChannelController = $deps->publicChannelController;
        $publicRequestContext = $deps->publicRequestContext;
        $input = $deps->input;
        $reservedPrefixes = is_array($deps->routeConfig['reserved_prefixes'] ?? null)
            ? array_values($deps->routeConfig['reserved_prefixes'])
            : [];

        // Single-segment route: channel landing first, then root page/redirect fallback.
        $router->add('GET', '/{slug}', static function (array $params) use ($publicChannelController, $publicRequestContext, $input, $reservedPrefixes): void {
            $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            $slug = RouteValidator::slugAllowedOrNotFound($slug, $reservedPrefixes, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            if ($slug === null) {
                return;
            }

            $publicChannelController()->channel($slug);
        });
    }
}
