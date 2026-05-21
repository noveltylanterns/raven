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
 * Registers public feed routes, including taxonomy-scoped feed endpoints.
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
                $channel = RouteValidator::slugOrNotFound($input, $params['channel'] ?? null, static function () use ($publicRequestContext): void {
                    $publicRequestContext()->notFound();
                });
                $channel = RouteValidator::slugAllowedOrNotFound($channel, $reservedPrefixes, static function () use ($publicRequestContext): void {
                    $publicRequestContext()->notFound();
                });
                if ($channel === null) {
                    return;
                }

                $publicFeedController()->rssFeed($channel);
            });

            if ($categoryPrefix !== '') {
                $router->add('GET', '/' . $rssFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                        $publicRequestContext()->notFound();
                    });
                    if ($slug === null) {
                        return;
                    }

                    $publicFeedController()->rssCategoryFeed($slug);
                });
            }

            if ($tagPrefix !== '') {
                $router->add('GET', '/' . $rssFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                        $publicRequestContext()->notFound();
                    });
                    if ($slug === null) {
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
                $channel = RouteValidator::slugOrNotFound($input, $params['channel'] ?? null, static function () use ($publicRequestContext): void {
                    $publicRequestContext()->notFound();
                });
                $channel = RouteValidator::slugAllowedOrNotFound($channel, $reservedPrefixes, static function () use ($publicRequestContext): void {
                    $publicRequestContext()->notFound();
                });
                if ($channel === null) {
                    return;
                }

                $publicFeedController()->atomFeed($channel);
            });

            if ($categoryPrefix !== '') {
                $router->add('GET', '/' . $atomFeedRoute . '/' . $categoryPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                        $publicRequestContext()->notFound();
                    });
                    if ($slug === null) {
                        return;
                    }

                    $publicFeedController()->atomCategoryFeed($slug);
                });
            }

            if ($tagPrefix !== '') {
                $router->add('GET', '/' . $atomFeedRoute . '/' . $tagPrefix . '/{slug}', static function (array $params) use ($publicFeedController, $publicRequestContext, $input): void {
                    $slug = RouteValidator::slugOrNotFound($input, $params['slug'] ?? null, static function () use ($publicRequestContext): void {
                        $publicRequestContext()->notFound();
                    });
                    if ($slug === null) {
                        return;
                    }

                    $publicFeedController()->atomTagFeed($slug);
                });
            }
        }
    }
}
