<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/FeedParser.php
 * Atom and RSS feed routing configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Config-backed routing policy helpers for the Atom and RSS feed feature.
 *
 * Reads feed.* config keys. There is no FeedDataParser because feed content
 * is assembled from channel and page records rather than a dedicated feed table.
 */
final class FeedParser
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * Initializes the feed route-config reader.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used when validating route prefixes.
     * @return void
     */
    public function __construct(Config $config, InputSanitizer $input)
    {
        $this->config = $config;
        $this->input = $input;
    }

    /**
     * Returns whether the feed feature is enabled in site config.
     *
     * @return bool True when feed routes should be registered.
     */
    public function feedEnabled(): bool
    {
        return ConfigParser::bool($this->config->get('feed.enabled', false), false);
    }

    /**
     * Returns the normalized RSS feed route prefix, or an empty string when feeds are disabled.
     *
     * @return string Route prefix slug (e.g. 'rss'), or '' when disabled.
     */
    public function rssFeedRoute(): string
    {
        if (!$this->feedEnabled()) {
            return '';
        }

        return RoutePrefixParser::normalize($this->input, (string) $this->config->get('feed.rss', 'rss'), 'rss', true);
    }

    /**
     * Returns the normalized Atom feed route prefix, or an empty string when feeds are disabled.
     *
     * @return string Route prefix slug (e.g. 'atom'), or '' when disabled.
     */
    public function atomFeedRoute(): string
    {
        if (!$this->feedEnabled()) {
            return '';
        }

        return RoutePrefixParser::normalize($this->input, (string) $this->config->get('feed.atom', 'atom'), 'atom', true);
    }

    /**
     * Returns the normalized list of channel slugs whose pages appear in the feed.
     *
     * A single-element ['all'] means all channels are included.
     *
     * @return array<int, string> Normalized channel slug list; ['all'] for all channels.
     */
    public function feedChannels(): array
    {
        $rawChannels = $this->config->get('feed.channels', null);
        if (!is_array($rawChannels)) {
            $rawChannels = ['all'];
        }

        $normalizedChannels = [];
        foreach ($rawChannels as $rawChannel) {
            $channel = strtolower(trim((string) $rawChannel));
            if ($channel === '') {
                continue;
            }

            if ($channel === 'all') {
                return ['all'];
            }

            $normalized = $channel === 'root' ? 'root' : $this->input->slug($channel);
            if ($normalized === null || $normalized === '') {
                continue;
            }

            $normalizedChannels[$normalized] = $normalized;
        }

        return array_values($normalizedChannels);
    }

    /**
     * Returns the maximum number of items to include in a feed.
     *
     * @return int Item limit, minimum 1.
     */
    public function feedItems(): int
    {
        $items = (int) $this->config->get('feed.items', 10);
        return max(1, $items);
    }

}
