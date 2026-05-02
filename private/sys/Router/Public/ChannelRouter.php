<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/ChannelRouter.php
 * Public channel-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers single-segment public channel routes.
 */
final class ChannelRouter
{
    /**
     * Registers channel routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving channel routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        self::register(
            $router,
            $deps->publicChannelController,
            $deps->publicRequestContext,
            $deps->input,
            $deps->routeConfig
        );
    }

    /**
     * Registers the public channel route family.
     *
     * @param RouteHandler $router Mutable router receiving channel routes.
     * @param callable(): object $publicChannelController Lazy public-channel controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{reserved_prefixes: array<int, string>} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        callable $publicChannelController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $reservedPrefixes = is_array($routeConfig['reserved_prefixes'] ?? null)
            ? array_values($routeConfig['reserved_prefixes'])
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
