<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicCategoryRouteRegistrar.php
 * Public category-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public category routes.
 */
final class PublicCategoryRouteRegistrar
{
    /**
     * Registers the public category route family.
     *
     * @param Router $router Mutable router receiving category routes.
     * @param callable(): object $publicCategoryController Lazy public-category controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{category_prefix: string} $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicCategoryController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $categoryPrefix = (string) ($routeConfig['category_prefix'] ?? '');
        if ($categoryPrefix === '') {
            return;
        }

        $categoryRouteBase = '/' . $categoryPrefix;
        $router->add('GET', $categoryRouteBase . '/{slug}', static function (array $params) use ($publicCategoryController, $publicRequestContext, $input): void {
            $slug = $input->slug($params['slug'] ?? null);

            if ($slug === null) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicCategoryController()->category($slug, 1);
        });

        $router->add('GET', $categoryRouteBase . '/{slug}/{page}', static function (array $params) use ($publicCategoryController, $publicRequestContext, $input): void {
            $slug = $input->slug($params['slug'] ?? null);
            $page = $input->int($params['page'] ?? null, 1);

            if ($slug === null || $page === null) {
                $publicRequestContext()->notFound();
                return;
            }

            $publicCategoryController()->category($slug, $page);
        });
    }
}
