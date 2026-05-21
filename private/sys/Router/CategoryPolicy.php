<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/CategoryPolicy.php
 * Category routing configuration helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;
use Raven\Core\Repository\ConfigRead;
use Raven\Core\Router\Public\PrefixResolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Static routing policy helpers for the category feature.
 *
 * Reads the category.enabled and category.prefix config keys so callers that only
 * need category routing policy do not have to construct a CategoryDataParser instance.
 */
final class CategoryPolicy
{
    /**
     * Returns whether public category routes are enabled system-wide.
     *
     * @param Config         $config Runtime site configuration.
     * @return bool                  True when category routes are enabled.
     */
    public static function categoryRouteEnabled(Config $config): bool
    {
        return ConfigRead::bool($config->get('category.enabled', false), false);
    }

    /**
     * Returns the normalized category route prefix.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used to validate the prefix slug.
     * @return string                Route prefix slug (e.g. 'cat').
     */
    public static function categoryRoutePrefix(Config $config, InputSanitizer $input): string
    {
        return PrefixResolver::normalize($input, (string) $config->get('category.prefix', 'cat'), 'cat', true);
    }
}
