<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Public/PublicPolicy.php
 * Public route-prefix and reserved-path policy assembly.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router\Public;

use Raven\Core\Config;
use Raven\Lib\Parser\CategoryRouteParser;
use Raven\Lib\Parser\FeedRouteParser;
use Raven\Lib\Parser\GroupRouteParser;
use Raven\Lib\Parser\TagRouteParser;
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
     *   profile_prefix: string,
     *   group_prefix: string,
     *   feeds_enabled: bool,
     *   rss_feed_route: string,
     *   atom_feed_route: string,
     *   reserved_prefixes: array<int, string>,
     *   bypass_availability_paths: array<int, string>
     * } Normalized routing policy for the public scope.
     */
    public static function build(Config $config, InputSanitizer $input): array
    {
        $groupRouteParser = new GroupRouteParser($config, $input);
        $feedRouteParser = new FeedRouteParser($config, $input);

        $panelPath = (string) $config->get('panel.path', 'panel');
        $categoryPrefix = CategoryRouteParser::categoryRoutePrefix($config, $input);
        $tagPrefix = TagRouteParser::tagRoutePrefix($config, $input);
        $profilePrefix = $groupRouteParser->profileRoutePrefix();
        $groupPrefix = $groupRouteParser->groupRoutePrefix();
        $feedsEnabled = $feedRouteParser->feedEnabled();
        $rssFeedRoute = $feedRouteParser->rssFeedRoute();
        $atomFeedRoute = $feedRouteParser->atomFeedRoute();

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
            'profile_prefix' => $profilePrefix,
            'group_prefix' => $groupPrefix,
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
