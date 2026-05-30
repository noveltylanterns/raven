<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/FeedPolicy.php
 * Atom and RSS feed routing configuration helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;
use Raven\Core\Repository\ConfigRead;
use Raven\Core\Router\Public\PrefixResolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Config-backed routing policy helpers for the Atom and RSS feed feature.
 *
 * Reads feed routing config keys used by public/panel route registrars.
 */
final class FeedPolicy
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
        return ConfigRead::bool($this->config->get('feed.enabled', false), false);
    }

    /**
     * Returns the normalized RSS feed route prefix, or an empty string when feeds are disabled.
     *
     * @return string Route prefix slug (e.g. 'rss'), or '' when disabled.
     */
    public function rssRoute(): string
    {
        // Disabled feeds should not expose any route prefix.
        if (!$this->feedEnabled()) {
            return '';
        }

        return PrefixResolver::normalize($this->input, (string) $this->config->get('feed.rss', 'rss'), 'rss', true);
    }

    /**
     * Returns the normalized Atom feed route prefix, or an empty string when feeds are disabled.
     *
     * @return string Route prefix slug (e.g. 'atom'), or '' when disabled.
     */
    public function atomRoute(): string
    {
        // Disabled feeds should not expose any route prefix.
        if (!$this->feedEnabled()) {
            return '';
        }

        return PrefixResolver::normalize($this->input, (string) $this->config->get('feed.atom', 'atom'), 'atom', true);
    }
}
