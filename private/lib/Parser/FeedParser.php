<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/FeedParser.php
 * Feed-content configuration parser helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;
use Raven\Lib\Security\InputSanitizer;

/**
 * Config-backed parser for feed content-selection settings.
 *
 * Reads `feed.channels` and `feed.items` keys used by public feed rendering.
 */
final class FeedParser
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * Initializes the feed-content configuration parser.
     *
     * @param Config $config Runtime site configuration reader.
     * @param InputSanitizer $input Shared input normalizer used for channel slug normalization.
     * @return void
     */
    public function __construct(Config $config, InputSanitizer $input)
    {
        $this->config = $config;
        $this->input = $input;
    }

    /**
     * Returns the normalized list of channel slugs whose pages appear in feeds.
     *
     * A single-element `['all']` means all channels are included.
     *
     * @return array<int, string> Normalized channel slug list; `['all']` for all channels.
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
     * Returns the maximum number of items to include in one feed response.
     *
     * @return int Item limit, minimum 1.
     */
    public function feedItems(): int
    {
        $items = (int) $this->config->get('feed.items', 10);
        return max(1, $items);
    }
}
