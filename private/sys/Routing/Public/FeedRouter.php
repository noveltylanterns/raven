<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/FeedRouter.php
 * Public feed-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers public feed routes, including taxonomy-scoped feed endpoints.
 */
final class FeedRouter
{
    /**
     * Registers the public feed route family.
     *
     * @param Router $router Mutable router receiving feed routes.
     * @param callable(): object $publicFeedController Lazy public-feed controller factory.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer for route params.
     * @param array{
     *   feeds_enabled: bool,
     *   rss_feed_route: string,
     *   atom_feed_route: string,
     *   category_prefix: string,
     *   tag_prefix: string,
     *   reserved_prefixes: array<int, string>
     * } $routeConfig Normalized public route policy.
     * @return void
     */
    public static function register(
        Router $router,
        callable $publicFeedController,
        callable $publicRequestContext,
        InputSanitizer $input,
        array $routeConfig
    ): void {
        $feedsEnabled = !empty($routeConfig['feeds_enabled']);
        $rssFeedRoute = (string) ($routeConfig['rss_feed_route'] ?? '');
        $atomFeedRoute = (string) ($routeConfig['atom_feed_route'] ?? '');
        $categoryPrefix = (string) ($routeConfig['category_prefix'] ?? '');
        $tagPrefix = (string) ($routeConfig['tag_prefix'] ?? '');
        $reservedPrefixes = is_array($routeConfig['reserved_prefixes'] ?? null)
            ? array_values($routeConfig['reserved_prefixes'])
            : [];

        if ($feedsEnabled && $rssFeedRoute !== '') {
            $router->add('GET', '/' . $rssFeedRoute, static function () use ($publicFeedController): void {
                $publicFeedController()->rssFeed();
            });

            $router->add('GET', '/' . $rssFeedRoute . '/{channel}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input, $reservedPrefixes): void {
                $channel = $input->slug($params['channel'] ?? null);

                if ($channel === null || in_array($channel, $reservedPrefixes, true)) {
                    $publicRequestContext()->notFound();
                    return;
                }

                $publicFeedController()->rssFeed($channel);
            });

            if ($categoryPrefix !== '') {
                $router->add('GET', '/' . $rssFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = $input->slug($params['slug'] ?? null);

                    if ($slug === null) {
                        $publicRequestContext()->notFound();
                        return;
                    }

                    $publicFeedController()->rssCategoryFeed($slug);
                });
            }

            if ($tagPrefix !== '') {
                $router->add('GET', '/' . $rssFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = $input->slug($params['slug'] ?? null);

                    if ($slug === null) {
                        $publicRequestContext()->notFound();
                        return;
                    }

                    $publicFeedController()->rssTagFeed($slug);
                });
            }
        }

        if ($feedsEnabled && $atomFeedRoute !== '') {
            $router->add('GET', '/' . $atomFeedRoute, static function () use ($publicFeedController): void {
                $publicFeedController()->atomFeed();
            });

            $router->add('GET', '/' . $atomFeedRoute . '/{channel}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input, $reservedPrefixes): void {
                $channel = $input->slug($params['channel'] ?? null);

                if ($channel === null || in_array($channel, $reservedPrefixes, true)) {
                    $publicRequestContext()->notFound();
                    return;
                }

                $publicFeedController()->atomFeed($channel);
            });

            if ($categoryPrefix !== '') {
                $router->add('GET', '/' . $atomFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = $input->slug($params['slug'] ?? null);

                    if ($slug === null) {
                        $publicRequestContext()->notFound();
                        return;
                    }

                    $publicFeedController()->atomCategoryFeed($slug);
                });
            }

            if ($tagPrefix !== '') {
                $router->add('GET', '/' . $atomFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = $input->slug($params['slug'] ?? null);

                    if ($slug === null) {
                        $publicRequestContext()->notFound();
                        return;
                    }

                    $publicFeedController()->atomTagFeed($slug);
                });
            }
        }

    }
}
