<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicProfileRouteRegistrar.php
 * Public profile and group-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public profile and group routes.
 */
final class PublicProfileRouteRegistrar
{
    /**
     * Registers the public profile/group route family.
     *
     * @param Router $router Mutable router receiving profile/group routes.
     * @param callable(): object $publicProfileController Lazy public-profile controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{
     *   profile_prefix: string,
     *   group_prefix: string
     * } $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicProfileController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $profilePrefix = (string) ($routeConfig['profile_prefix'] ?? '');
        $groupPrefix = (string) ($routeConfig['group_prefix'] ?? '');

        if ($profilePrefix !== '') {
            $profileRouteBase = '/' . $profilePrefix;
            $router->add('GET', $profileRouteBase . '/{username}', static function (array $params) use ($publicProfileController, $publicRequestContext, $input): void {
                $username = $input->text(rawurldecode((string) ($params['username'] ?? '')), 254);

                if ($username === '') {
                    $publicRequestContext()->notFound();
                    return;
                }

                $publicProfileController()->profile($username);
            });
        }

        if ($groupPrefix !== '') {
            $groupRouteBase = '/' . $groupPrefix;
            $router->add('GET', $groupRouteBase . '/{slug}', static function (array $params) use ($publicProfileController, $publicRequestContext, $input): void {
                $slug = $input->slug($params['slug'] ?? null);

                if ($slug === null) {
                    $publicRequestContext()->notFound();
                    return;
                }

                $publicProfileController()->group($slug);
            });
        }
    }
}
