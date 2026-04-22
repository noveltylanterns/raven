<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicTagRouteRegistrar.php
 * Public tag-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public tag routes.
 */
final class PublicTagRouteRegistrar
{
    /**
     * Registers the public tag route family.
     *
     * @param Router $router Mutable router receiving tag routes.
     * @param callable(): object $publicTagController Lazy public-tag controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{tag_prefix: string} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicTagController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $tagPrefix = (string) ($routeConfig['tag_prefix'] ?? '');
        if ($tagPrefix === '') {
            return;
        }

        $tagRouteBase = '/' . $tagPrefix;
        $router->add('GET', $tagRouteBase . '/{slug}', static function (array $params) use ($publicTagController, $publicRequestContext, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicTagController()->tag($slug, 1);
        });

        $router->add('GET', $tagRouteBase . '/{slug}/{page}', static function (array $params) use ($publicTagController, $publicRequestContext, $input): void {
            $slug = $input->slug($params['slug'] ?? null);
            $page = $input->int($params['page'] ?? null, 1);

            if ($slug === null || $page === null) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicTagController()->tag($slug, $page);
        });
    }
}
