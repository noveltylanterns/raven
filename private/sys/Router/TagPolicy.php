<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/TagPolicy.php
 * Tag routing configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;
use Raven\Core\Repository\ConfigRead;
use Raven\Core\Router\Public\PrefixResolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Static routing policy helpers for the tag feature.
 *
 * Reads the tag.enabled and tag.prefix config keys so callers that only
 * need tag routing policy do not have to construct a TagDataParser instance.
 */
final class TagPolicy
{
    /**
     * Returns the normalized tag route prefix, or an empty string when tags are disabled.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used to validate the prefix slug.
     * @return string                Route prefix slug (e.g. 'tag'), or '' when tags are disabled.
     */
    public static function routePrefix(Config $config, InputSanitizer $input): string
    {
        if (!ConfigRead::bool($config->get('tag.enabled', false), false)) {
            return '';
        }

        return PrefixResolver::normalize($input, (string) $config->get('tag.prefix', 'tag'), 'tag', true);
    }
}
