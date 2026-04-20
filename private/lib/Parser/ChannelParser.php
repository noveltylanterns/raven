<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ChannelParser.php
 * Channel, page-route, category, and tag configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Reads and normalizes page-route, category, and tag configuration from site config.
 */
final class ChannelParser
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
     * Returns the global page route mode configured for the site.
     *
     * @return string One of `slug` or `id`.
     */
    public function globalPageRouteMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('content.mode', 'slug')));
        return in_array($mode, ['slug', 'id'], true) ? $mode : 'slug';
    }

    /**
     * Normalizes a channel route-mode value while preserving `inherit`.
     *
     * @param string $value Raw channel route-mode string.
     * @return string Normalized route-mode key.
     */
    public function normalizeChannelRouteMode(string $value): string
    {
        return ModeParser::normalizeChannelRouteMode($value);
    }

    /**
     * Returns the effective route mode for one channel, resolving `inherit` to the global default.
     *
     * @param string $channelValue Per-channel route-mode value from the channel record.
     * @return string Concrete route-mode key used for lookups and path generation.
     */
    public function effectiveChannelRouteMode(string $channelValue): string
    {
        $mode = $this->normalizeChannelRouteMode($channelValue);
        return $mode === 'inherit' ? $this->globalPageRouteMode() : ModeParser::normalizeRouteMode($mode);
    }

    /**
     * Resolves the effective word separator for one channel's page routes.
     *
     * @param string $channelValue Per-channel separator value from the channel record.
     * @return string Resolved separator: `-` or `_`.
     */
    public function resolveChannelRouteSeparator(string $channelValue): string
    {
        return ModeParser::resolveSeparator(
            $channelValue,
            (string) $this->config->get('content.separator', '-')
        );
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
        return PanelParser::normalizeRoutePrefix($this->input, $configured, $fallback, $allowBlank);
    }
}
