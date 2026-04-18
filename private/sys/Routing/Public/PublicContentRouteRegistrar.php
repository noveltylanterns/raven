<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicContentRouteRegistrar.php
 * Public homepage/content-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers homepage, channel, and page routes for the public runtime.
 */
final class PublicContentRouteRegistrar
{
    /**
     * Registers the public content route family.
     *
     * @param Router $router Mutable router receiving content routes.
     * @param callable(): object $publicContentController Lazy public-content controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{reserved_prefixes: array<int, string>} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicContentController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $reservedPrefixes = is_array($routeConfig['reserved_prefixes'] ?? null)
            ? array_values($routeConfig['reserved_prefixes'])
            : [];

        $router->add('GET', '/', static function () use ($publicContentController): void {
            $publicContentController()->home();
        });

        // Single-segment route: channel landing first, then root page/redirect fallback.
        $router->add('GET', '/{slug}', static function (array $params) use ($publicContentController, $publicRequestContext, $input, $reservedPrefixes): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null || in_array($slug, $reservedPrefixes, true)) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicContentController()->channel($slug);
        });

        // Channel + page route for pages assigned to channels.
        $router->add('GET', '/{channel}/{slug}', static function (array $params) use ($publicContentController, $publicRequestContext, $input, $reservedPrefixes): void {
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

            $publicContentController()->page($slugRaw, $channel);
        });
    }
}
