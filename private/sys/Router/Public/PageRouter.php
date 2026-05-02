<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PageRouter.php
 * Public homepage/page-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers homepage and channel-qualified page routes for the public runtime.
 */
final class PageRouter
{
    /**
     * Registers page routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving page routes.
     * @param PublicRouteDeps $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicRouteDeps $deps): void
    {
        self::register(
            $router,
            $deps->publicPageController,
            $deps->publicRequestContext,
            $deps->input,
            $deps->routeConfig
        );
    }

    /**
     * Registers the public page route family.
     *
     * @param RouteHandler $router Mutable router receiving page routes.
     * @param callable(): object $publicPageController Lazy public-page controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{reserved_prefixes: array<int, string>} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        callable $publicPageController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $reservedPrefixes = is_array($routeConfig['reserved_prefixes'] ?? null)
            ? array_values($routeConfig['reserved_prefixes'])
            : [];

        $router->add('GET', '/', static function () use ($publicPageController): void {
            $publicPageController()->home();
        });

        // Channel + page route for pages assigned to channels.
        $router->add('GET', '/{channel}/{slug}', static function (array $params) use ($publicPageController, $publicRequestContext, $input, $reservedPrefixes): void {
            $channel = RouteValidator::slugOrNotFound($input, $params['channel'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            $slugRaw = strtolower(trim((string) ($params['slug'] ?? '')));

            if (
                $channel === null
                || $slugRaw === ''
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slugRaw) !== 1
                || in_array($channel, $reservedPrefixes, true)
            ) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicPageController()->page($slugRaw, $channel);
        });
    }
}
