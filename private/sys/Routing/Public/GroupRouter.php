<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/GroupRouter.php
 * Public group-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public group routes.
 */
final class GroupRouter
{
    /**
     * Registers the public group route family.
     *
     * @param Router $router Mutable router receiving group routes.
     * @param callable(): object $publicGroupController Lazy public-group controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{group_prefix: string} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicGroupController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $groupPrefix = (string) ($routeConfig['group_prefix'] ?? '');
        if ($groupPrefix === '') {
            return;
        }

        $groupRouteBase = '/' . $groupPrefix;
        $router->add('GET', $groupRouteBase . '/{slug}', static function (array $params) use ($publicGroupController, $publicRequestContext, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicGroupController()->group($slug);
        });
    }
}
