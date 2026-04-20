<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/TagRouteParser.php
 * Tag routing configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Static routing policy helpers for the tag feature.
 *
 * Reads the tag.enabled and tag.prefix config keys so callers that only
 * need tag routing policy do not have to construct a TagDataParser instance.
 */
final class TagRouteParser
{
    /**
     * Returns whether the tag feature is enabled in site config.
     *
     * @param Config $config Runtime site configuration.
     * @return bool          True when tag routes should be registered.
     */
    public static function tagEnabled(Config $config): bool
    {
        return ConfigParser::bool($config->get('tag.enabled', false), false);
    }

    /**
     * Returns the normalized tag route prefix, or an empty string when tags are disabled.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used to validate the prefix slug.
     * @return string                Route prefix slug (e.g. 'tag'), or '' when tags are disabled.
     */
    public static function tagRoutePrefix(Config $config, InputSanitizer $input): string
    {
        if (!self::tagEnabled($config)) {
            return '';
        }

        return PanelParser::normalizeRoutePrefix($input, (string) $config->get('tag.prefix', 'tag'), 'tag', true);
    }
}
