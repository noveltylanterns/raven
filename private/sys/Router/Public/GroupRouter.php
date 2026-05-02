<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/GroupRouter.php
 * Public group-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public group routes.
 */
final class GroupRouter
{
    /**
     * Registers group routes from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving group routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        self::register(
            $router,
            $deps->publicGroupController,
            $deps->publicRequestContext,
            $deps->input,
            $deps->routeConfig
        );
    }

    /**
     * Registers the public group route family.
     *
     * @param RouteHandler $router Mutable router receiving group routes.
     * @param callable(): object $publicGroupController Lazy public-group controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{group_prefix: string} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        RouteHandler $router,
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
            $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            if ($slug === null) {
                return;
            }

            $publicGroupController()->group($slug);
        });
    }
}
