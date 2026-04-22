<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicContentRouteRegistrar.php
 * Public homepage/page-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers homepage and channel-qualified page routes for the public runtime.
 */
final class PublicContentRouteRegistrar
{
    /**
     * Registers the public page route family.
     *
     * @param Router $router Mutable router receiving page routes.
     * @param callable(): object $publicPageController Lazy public-page controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{reserved_prefixes: array<int, string>} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
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
            $channel = $input->slug($params['channel'] ?? null);
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
