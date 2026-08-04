<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/FeedRouter.php
 * Public feed-route registration.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Core\Router\RouteValidator;

/**
 * Registers public feed routes, including taxonomy and parent-aware channel feeds.
 */
final class FeedRouter
{
    /**
     * Registers the public feed route family from one shared dependency payload.
     *
     * @param RouteHandler $router Mutable router receiving feed routes.
     * @param PublicPayload $deps Shared public route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PublicPayload $deps): void
    {
        $publicFeedController = $deps->publicFeedController;
        $publicRequestContext = $deps->publicRequestContext;
        $input = $deps->input;
        $routeConfig = $deps->routeConfig;

        if (empty($routeConfig['feeds_enabled'])) {
            return;
        }

        $categoryPrefix = (string) ($routeConfig['category_prefix'] ?? '');
        $tagPrefix = (string) ($routeConfig['tag_prefix'] ?? '');
        $reservedPrefixes = is_array($routeConfig['reserved_prefixes'] ?? null)
            ? array_values($routeConfig['reserved_prefixes'])
            : [];

        // Register exact taxonomy routes before the final-segment channel route so
        // reserved prefixes remain available for category/tag feed endpoints.
        $registerFormat = static function (
            string $route,
            string $globalMethod,
            string $categoryMethod,
            string $tagMethod
        ) use (
            $router,
            $publicFeedController,
            $publicRequestContext,
            $input,
            $categoryPrefix,
            $tagPrefix,
            $reservedPrefixes
        ): void {
            if ($route === '') {
                return;
            }

            $router->add('GET', '/' . $route, static function () use ($publicFeedController, $globalMethod): void {
                $publicFeedController()->{$globalMethod}();
            });

            if ($categoryPrefix !== '') {
                $router->add('GET', '/' . $route . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input, $categoryMethod): void {
                    $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                        $publicRequestContext()->notFound();
                    });
                    if ($slug === null) {
                        return;
                    }

                    $publicFeedController()->{$categoryMethod}($slug);
                });
            }

            if ($tagPrefix !== '') {
                $router->add('GET', '/' . $route . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input, $tagMethod): void {
                    $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                        $publicRequestContext()->notFound();
                    });
                    if ($slug === null) {
                        return;
                    }

                    $publicFeedController()->{$tagMethod}($slug);
                });
            }

            $router->add('GET', '/' . $route . '/{channel_path...}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input, $reservedPrefixes, $globalMethod): void {
                $rawPath = trim((string) ($params['channel_path'] ?? ''), '/');
                $segments = $rawPath === '' ? [] : explode('/', $rawPath);
                $normalizedSegments = [];
                foreach ($segments as $segment) {
                    $normalized = $input->slug($segment);
                    if ($normalized === null || $normalized === '') {
                        $publicRequestContext()->notFound();
                        return;
                    }

                    $normalizedSegments[] = $normalized;
                }

                // System/taxonomy prefixes cannot be interpreted as channel roots.
                if ($normalizedSegments === [] || in_array($normalizedSegments[0], $reservedPrefixes, true)) {
                    $publicRequestContext()->notFound();
                    return;
                }

                $publicFeedController()->{$globalMethod}(implode('/', $normalizedSegments));
            });
        };

        $registerFormat(
            (string) ($routeConfig['rss_feed_route'] ?? ''),
            'rssFeed',
            'rssCategoryFeed',
            'rssTagFeed'
        );
        $registerFormat(
            (string) ($routeConfig['atom_feed_route'] ?? ''),
            'atomFeed',
            'atomCategoryFeed',
            'atomTagFeed'
        );
    }
}
