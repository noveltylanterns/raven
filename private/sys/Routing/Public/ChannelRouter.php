<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/ChannelRouter.php
 * Public channel-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers single-segment public channel routes.
 */
final class ChannelRouter
{
    /**
     * Registers the public channel route family.
     *
     * @param Router $router Mutable router receiving channel routes.
     * @param callable(): object $publicChannelController Lazy public-channel controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{reserved_prefixes: array<int, string>} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
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
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null || in_array($slug, $reservedPrefixes, true)) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicChannelController()->channel($slug);
        });
    }
}
