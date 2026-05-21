<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PublicPolicy.php
 * Public route-prefix and reserved-path policy assembly.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Config;
use Raven\Core\Router\CategoryPolicy;
use Raven\Core\Router\FeedPolicy;
use Raven\Core\Router\GroupPolicy;
use Raven\Core\Router\TagPolicy;
use Raven\Core\Router\UserPolicy;
use Raven\Lib\Security\InputSanitizer;

/**
 * Builds normalized public-routing policy values from runtime config.
 */
final class PublicPolicy
{
    /**
     * Builds public route policy values used by the public route registrars.
     *
     * @param Config $config Runtime config for the current request.
     * @param InputSanitizer $input Shared input normalizer used by route config helpers.
     * @return array{
     *   category_prefix: string,
     *   tag_prefix: string,
     *   category_route_enabled: bool,
     *   tag_route_enabled: bool,
     *   profile_prefix: string,
     *   group_prefix: string,
     *   profile_route_enabled: bool,
     *   group_route_enabled: bool,
     *   feeds_enabled: bool,
     *   rss_feed_route: string,
     *   atom_feed_route: string,
     *   reserved_prefixes: array<int, string>,
     *   bypass_availability_paths: array<int, string>
     * } Normalized routing policy for the public scope.
     */
    public static function build(Config $config, InputSanitizer $input): array
    {
        $userRouteParser = new UserPolicy($config, $input);
        $groupRouteParser = new GroupPolicy($config, $input);
        $feedRouteParser = new FeedPolicy($config, $input);

        $panelPath = (string) $config->get('panel.path', 'panel');
        $categoryRouteEnabled = CategoryPolicy::categoryRouteEnabled($config);
        $tagRouteEnabled = TagPolicy::tagRouteEnabled($config);
        $profileRouteEnabled = $userRouteParser->profileRouteEnabled();
        $groupRouteEnabled = $groupRouteParser->groupRouteEnabled();

        $categoryPrefix = $categoryRouteEnabled
            ? CategoryPolicy::categoryRoutePrefix($config, $input)
            : '';
        $tagPrefix = $tagRouteEnabled
            ? TagPolicy::tagRoutePrefix($config, $input)
            : '';
        $profilePrefix = $profileRouteEnabled ? $userRouteParser->profileRoutePrefix() : '';
        $groupPrefix = $groupRouteEnabled ? $groupRouteParser->groupRoutePrefix() : '';
        $feedsEnabled = $feedRouteParser->feedEnabled();
        $rssFeedRoute = $feedRouteParser->rssRoute();
        $atomFeedRoute = $feedRouteParser->atomRoute();

        // Keep the two feed route slugs distinct even when config is edited to collide.
        if ($rssFeedRoute !== '' && $atomFeedRoute !== '' && $rssFeedRoute === $atomFeedRoute) {
            if ($rssFeedRoute !== 'rss') {
                $rssFeedRoute = 'rss';
            } elseif ($atomFeedRoute !== 'atom') {
                $atomFeedRoute = 'atom';
            } else {
                $atomFeedRoute = '';
            }
        }

        // Keep category/tag prefixes distinct even if config is manually edited to collide.
        if ($categoryPrefix !== '' && $tagPrefix !== '' && $categoryPrefix === $tagPrefix) {
            if ($categoryPrefix !== 'cat') {
                $categoryPrefix = 'cat';
            } else {
                $tagPrefix = 'tag';
            }
        }

        if ($groupPrefix !== '' && in_array($groupPrefix, [$categoryPrefix, $tagPrefix], true)) {
            $groupPrefix = 'group';
            if (in_array($groupPrefix, [$categoryPrefix, $tagPrefix], true)) {
                $groupPrefix = 'grp';
            }
        }

        if ($profilePrefix !== '' && in_array($profilePrefix, [$categoryPrefix, $tagPrefix, $groupPrefix], true)) {
            $profilePrefix = 'user';
            if (in_array($profilePrefix, [$categoryPrefix, $tagPrefix, $groupPrefix], true)) {
                $profilePrefix = 'profile';
            }
        }

        if ($groupPrefix !== '' && $groupPrefix === $profilePrefix) {
            $groupPrefix = 'group';
            if ($groupPrefix === $profilePrefix || in_array($groupPrefix, [$categoryPrefix, $tagPrefix], true)) {
                $groupPrefix = 'grp';
            }
        }

        return [
            'category_prefix' => $categoryPrefix,
            'tag_prefix' => $tagPrefix,
            'category_route_enabled' => $categoryRouteEnabled,
            'tag_route_enabled' => $tagRouteEnabled,
            'profile_prefix' => $profilePrefix,
            'group_prefix' => $groupPrefix,
            'profile_route_enabled' => $profileRouteEnabled,
            'group_route_enabled' => $groupRouteEnabled,
            'feeds_enabled' => $feedsEnabled,
            'rss_feed_route' => $rssFeedRoute,
            'atom_feed_route' => $atomFeedRoute,
            'reserved_prefixes' => array_values(array_unique(array_filter([
                trim($panelPath, '/'),
                'boot',
                'mce',
                'theme',
                'login',
                'register',
                'forms',
                $categoryPrefix,
                $tagPrefix,
                $profilePrefix,
                $groupPrefix,
                $feedsEnabled ? $rssFeedRoute : '',
                $feedsEnabled ? $atomFeedRoute : '',
            ], static fn (string $value): bool => trim($value) !== ''))),
            // Login/register must stay reachable even when the public site is private/disabled.
            'bypass_availability_paths' => ['/login', '/register'],
        ];
    }
}
