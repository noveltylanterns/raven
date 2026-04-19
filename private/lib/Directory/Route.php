<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Route.php
 * Core page-routing helpers and route-configuration service for the public and panel scopes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Core\Config;
use Raven\Lib\Config\ConfigParser;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Security\InputSanitizer;

/**
 * Core routing configuration service.
 *
 * Owns channel-route mode and separator resolution, and composes Channel, Group, and Feed
 * to provide a unified routing-config API for controllers and route registrars.
 * Replaces the former RouteConfigService in lib/Routing/.
 */
final class Route
{
    private Config $config;
    private InputSanitizer $input;
    private Channel $channel;
    private Group $group;
    private Feed $feed;

    /**
     * Initializes the route-configuration service and its composed sub-services.
     *
     * @param Config         $config Runtime site configuration.
     * @param InputSanitizer $input  Input normalizer used when validating route prefixes.
     */
    public function __construct(Config $config, InputSanitizer $input)
    {
        $this->config = $config;
        $this->input = $input;
        $this->channel = new Channel($config, $input);
        $this->group = new Group($config, $input);
        $this->feed = new Feed($config, $input);
    }

    // -------------------------------------------------------------------------
    // Core routing helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves the effective word separator for a channel's page routes.
     *
     * Falls back to the global separator config when the channel-level value is 'inherit'.
     *
     * @param string $channelValue Per-channel separator value from the channel record.
     * @return string              Resolved separator: '-' or '_'.
     */
    public function resolveChannelRouteSeparator(string $channelValue): string
    {
        return Mode::resolveSeparator(
            $channelValue,
            (string) $this->config->get('content.separator', '-')
        );
    }

    /**
     * Returns the global page route mode configured for the site.
     *
     * @return string 'slug' or 'id'.
     */
    public function globalPageRouteMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('content.mode', 'slug')));
        return in_array($mode, ['slug', 'id'], true) ? $mode : 'slug';
    }

    /**
     * Normalizes a channel route-mode value, preserving 'inherit'.
     *
     * @param string $value Raw channel route-mode string.
     * @return string       One of 'inherit', 'slug', 'date_slug', 'month_slug', 'id', 'date_id', 'month_id'.
     */
    public function normalizeChannelRouteMode(string $value): string
    {
        return Mode::normalizeChannelRouteMode($value);
    }

    /**
     * Returns the effective route mode for a channel, resolving 'inherit' to the global default.
     *
     * @param string $channelValue Per-channel route-mode value from the channel record.
     * @return string              Resolved concrete route mode string.
     */
    public function effectiveChannelRouteMode(string $channelValue): string
    {
        $mode = $this->normalizeChannelRouteMode($channelValue);
        return $mode === 'inherit' ? $this->globalPageRouteMode() : Mode::normalizeRouteMode($mode);
    }

    /**
     * Normalizes a raw boolean-like config value to a bool.
     *
     * @param mixed $value   Raw config value.
     * @param bool  $default Default when the value cannot be interpreted.
     * @return bool          Parsed boolean.
     */
    public function configBool(mixed $value, bool $default = false): bool
    {
        return ConfigParser::bool($value, $default);
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

    // -------------------------------------------------------------------------
    // Category / tag config (via Channel)
    // -------------------------------------------------------------------------

    /**
     * Returns whether the category feature is enabled in site config.
     *
     * @return bool True when category routes should be registered.
     */
    public function categoryEnabled(): bool
    {
        return $this->channel->categoryEnabled();
    }

    /**
     * Returns whether the tag feature is enabled in site config.
     *
     * @return bool True when tag routes should be registered.
     */
    public function tagEnabled(): bool
    {
        return $this->channel->tagEnabled();
    }

    /**
     * Returns the normalized category route prefix, or empty string when disabled.
     *
     * @return string Route prefix slug, or '' when categories are disabled.
     */
    public function categoryRoutePrefix(): string
    {
        return $this->channel->categoryRoutePrefix();
    }

    /**
     * Returns the normalized tag route prefix, or empty string when disabled.
     *
     * @return string Route prefix slug, or '' when tags are disabled.
     */
    public function tagRoutePrefix(): string
    {
        return $this->channel->tagRoutePrefix();
    }

    // -------------------------------------------------------------------------
    // Feed config (via Feed)
    // -------------------------------------------------------------------------

    /**
     * Returns whether feed routes are enabled in site config.
     *
     * @return bool True when feed routes should be registered.
     */
    public function feedEnabled(): bool
    {
        return $this->feed->feedEnabled();
    }

    /**
     * Returns the normalized RSS feed route prefix, or empty string when feeds are disabled.
     *
     * @return string Route prefix slug, or '' when feeds are disabled.
     */
    public function rssFeedRoute(): string
    {
        return $this->feed->rssFeedRoute();
    }

    /**
     * Returns the normalized Atom feed route prefix, or empty string when feeds are disabled.
     *
     * @return string Route prefix slug, or '' when feeds are disabled.
     */
    public function atomFeedRoute(): string
    {
        return $this->feed->atomFeedRoute();
    }

    /**
     * Returns the normalized list of channel slugs whose pages appear in feeds.
     *
     * @return array<int, string> Normalized channel slug list; ['all'] for all channels.
     */
    public function feedChannels(): array
    {
        return $this->feed->feedChannels();
    }

    /**
     * Returns the maximum number of items to include in a feed.
     *
     * @return int Item limit, minimum 1.
     */
    public function feedItems(): int
    {
        return $this->feed->feedItems();
    }

    // -------------------------------------------------------------------------
    // Profile / group config (via Group)
    // -------------------------------------------------------------------------

    /**
     * Returns the normalized user-profile route prefix.
     *
     * @return string Route prefix slug (e.g. 'user').
     */
    public function profileRoutePrefix(): string
    {
        return $this->group->profileRoutePrefix();
    }

    /**
     * Returns the normalized profile URL selector mode.
     *
     * @return string One of 'id', 'username', or 'string'.
     */
    public function profileSelector(): string
    {
        return $this->group->profileSelector();
    }

    /**
     * Returns the normalized profile visibility mode.
     *
     * @return string One of 'public_full', 'public_limited', 'private', or 'disabled'.
     */
    public function profileMode(): string
    {
        return $this->group->profileMode();
    }

    /**
     * Returns whether profile routes should appear in the routing table.
     *
     * @return bool True when profiles have a prefix and are not fully disabled.
     */
    public function profileRoutesEnabledForRoutingTable(): bool
    {
        return $this->group->profileRoutesEnabledForRoutingTable();
    }

    /**
     * Returns the normalized group route prefix.
     *
     * @return string Route prefix slug (e.g. 'group').
     */
    public function groupRoutePrefix(): string
    {
        return $this->group->groupRoutePrefix();
    }

    /**
     * Returns the normalized group visibility mode.
     *
     * @return string One of 'public_full', 'public_limited', 'private', or 'disabled'.
     */
    public function groupMode(): string
    {
        return $this->group->groupMode();
    }

    /**
     * Returns whether group routes should appear in the routing table.
     *
     * @return bool True when groups have a prefix and are not fully disabled.
     */
    public function groupRoutesEnabledForRoutingTable(): bool
    {
        return $this->group->groupRoutesEnabledForRoutingTable();
    }

    /**
     * Returns the normalized user registration mode.
     *
     * @return string One of 'open', 'invite', or 'closed'.
     */
    public function registrationMode(): string
    {
        return $this->group->registrationMode();
    }
}
