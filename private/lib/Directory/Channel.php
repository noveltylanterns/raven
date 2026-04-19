<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Channel.php
 * Category and tag route-prefix configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Core\Config;
use Raven\Lib\Config\ConfigParser;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Security\InputSanitizer;

/**
 * Reads and normalizes category and tag routing configuration from site config.
 */
final class Channel
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * Initializes the channel route-config reader.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used when validating route prefixes.
     */
    public function __construct(Config $config, InputSanitizer $input)
    {
        $this->config = $config;
        $this->input = $input;
    }

    /**
     * Returns whether the category feature is enabled in site config.
     *
     * @return bool True when category routes should be registered.
     */
    public function categoryEnabled(): bool
    {
        return ConfigParser::bool($this->config->get('category.enabled', false), false);
    }

    /**
     * Returns whether the tag feature is enabled in site config.
     *
     * @return bool True when tag routes should be registered.
     */
    public function tagEnabled(): bool
    {
        return ConfigParser::bool($this->config->get('tag.enabled', false), false);
    }

    /**
     * Returns the normalized category route prefix, or an empty string when categories are disabled.
     *
     * @return string Route prefix slug (e.g. 'cat'), or '' when disabled.
     */
    public function categoryRoutePrefix(): string
    {
        if (!$this->categoryEnabled()) {
            return '';
        }

        return $this->normalizeRoutePrefix((string) $this->config->get('category.prefix', 'cat'), 'cat', true);
    }

    /**
     * Returns the normalized tag route prefix, or an empty string when tags are disabled.
     *
     * @return string Route prefix slug (e.g. 'tag'), or '' when disabled.
     */
    public function tagRoutePrefix(): string
    {
        if (!$this->tagEnabled()) {
            return '';
        }

        return $this->normalizeRoutePrefix((string) $this->config->get('tag.prefix', 'tag'), 'tag', true);
    }

    /**
     * Normalizes a raw route-prefix string through the panel URL helper.
     *
     * @param string $configured Configured prefix value.
     * @param string $fallback   Fallback prefix when the configured value is invalid.
     * @param bool   $allowBlank When true, an empty result is accepted; otherwise the fallback is used.
     * @return string            Normalized route prefix.
     */
    public function normalizeRoutePrefix(string $configured, string $fallback, bool $allowBlank = false): string
    {
        return PanelUrl::normalizeRoutePrefix($this->input, $configured, $fallback, $allowBlank);
    }
}
