<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PrefixRouter.php
 * Shared public route builder for configurable-prefix slug-listing routes with pagination.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Closure;
use Raven\Core\Router\RouteValidator;
use Raven\Core\Router\RouteHandler;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared route-pattern builder for configurable-prefix slug-listing routes with optional pagination.
 *
 * Registers /{prefix}/{slug} and /{prefix}/{slug}/{page} under a prefix read
 * from a routeConfig key. CategoryRouter and TagRouter delegate here so the
 * two-route slug+pagination layout lives in one place while each registrar
 * supplies its own controller closure.
 */
final class PrefixRouter
{
    /**
     * Registers slug-only and slug+page routes under a configurable prefix.
     *
     * Reads the URL prefix from $routeConfig[$configKey] and registers two routes:
     * /{prefix}/{slug} (page defaults to 1) and /{prefix}/{slug}/{page}. Both
     * validate the slug; the paged route additionally validates page as a positive
     * integer. Registration is skipped entirely when the prefix is empty or unset.
     *
     * @param RouteHandler $router Mutable router receiving the prefix routes.
     * @param string $configKey routeConfig key that holds the URL prefix (e.g. 'category_prefix').
     * @param array<string, mixed> $routeConfig Normalized public route policy map.
     * @param Closure $onSlug Handler for /{prefix}/{slug} — receives (string $slug).
     * @param Closure $onSlugPage Handler for /{prefix}/{slug}/{page} — receives (string $slug, int $page).
     * @param callable(): object $publicRequestContext Lazy public request-context factory for not-found responses.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        string $configKey,
        array $routeConfig,
        Closure $onSlug,
        Closure $onSlugPage,
        callable $publicRequestContext,
        InputSanitizer $input
    ): void {
        $prefix = (string) ($routeConfig[$configKey] ?? '');
        if ($prefix === '') {
            return;
        }

        $base = '/' . $prefix;

        $router->add('GET', $base . '/{slug}', static function (array $params) use ($onSlug, $publicRequestContext, $input): void {
            $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            });
            if ($slug === null) {
                return;
            }
            $onSlug($slug);
        });

        $router->add('GET', $base . '/{slug}/{page}', static function (array $params) use ($onSlugPage, $publicRequestContext, $input): void {
            $notFound = static function () use ($publicRequestContext): void {
                $publicRequestContext()->notFound();
            };
            $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, $notFound);
            $page = RouteValidator::intOrNotFound($input, $params['page'] ?? null, 1, $notFound);
            if ($slug === null || $page === null) {
                return;
            }
            $onSlugPage($slug, $page);
        });
    }
}
