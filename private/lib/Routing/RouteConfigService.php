<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Core\Config;
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared route-related configuration and mode normalization helpers.
 */
final class RouteConfigService
{
    private Config $config;
    private InputSanitizer $input;

    public function __construct(Config $config, InputSanitizer $input)
    {
        $this->config = $config;
        $this->input = $input;
    }

    public function categoryRoutePrefix(): string
    {
        if (!$this->categoryEnabled()) {
            return '';
        }

        return $this->normalizeRoutePrefix((string) $this->config->get('category.prefix', 'cat'), 'cat', true);
    }

    public function tagRoutePrefix(): string
    {
        if (!$this->tagEnabled()) {
            return '';
        }

        return $this->normalizeRoutePrefix((string) $this->config->get('tag.prefix', 'tag'), 'tag', true);
    }

    public function categoryEnabled(): bool
    {
        return $this->configBool($this->config->get('category.enabled', false), false);
    }

    public function tagEnabled(): bool
    {
        return $this->configBool($this->config->get('tag.enabled', false), false);
    }

    public function feedEnabled(): bool
    {
        return $this->configBool($this->config->get('feed.enabled', false), false);
    }

    public function rssFeedRoute(): string
    {
        if (!$this->feedEnabled()) {
            return '';
        }

        return $this->normalizeRoutePrefix((string) $this->config->get('feed.rss', 'rss'), 'rss', true);
    }

    public function atomFeedRoute(): string
    {
        if (!$this->feedEnabled()) {
            return '';
        }

        return $this->normalizeRoutePrefix((string) $this->config->get('feed.atom', 'atom'), 'atom', true);
    }

    /**
     * @return array<int, string>
     */
    public function feedChannels(): array
    {
        $rawChannels = $this->config->get('feed.channels', null);
        if (!is_array($rawChannels)) {
            $legacyChannel = trim((string) $this->config->get('feed.channel', ''));
            $rawChannels = $legacyChannel === '' ? ['all'] : [$legacyChannel];
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

    public function feedItems(): int
    {
        $items = (int) $this->config->get('feed.items', 10);
        return max(1, $items);
    }

    public function configBool(mixed $value, bool $default = false): bool
    {
        return ConfigValueParser::bool($value, $default);
    }

    public function profileRoutePrefix(): string
    {
        return $this->normalizeRoutePrefix((string) $this->config->get('user.prefix', 'user'), 'user', true);
    }

    public function profileMode(): string
    {
        return $this->normalizeMode((string) $this->config->get('user.privacy', 'disabled'), ['public_full', 'public_limited', 'private', 'disabled'], 'disabled');
    }

    public function profileRoutesEnabledForRoutingTable(): bool
    {
        return $this->profileRoutePrefix() !== '' && in_array($this->profileMode(), ['public_full', 'public_limited', 'private'], true);
    }

    public function groupRoutePrefix(): string
    {
        return $this->normalizeRoutePrefix((string) $this->config->get('group.prefix', 'group'), 'group', true);
    }

    public function groupMode(): string
    {
        return $this->normalizeMode((string) $this->config->get('group.privacy', 'disabled'), ['public_full', 'public_limited', 'private', 'disabled'], 'disabled', ['public' => 'public_full']);
    }

    public function groupRoutesEnabledForRoutingTable(): bool
    {
        return $this->groupRoutePrefix() !== '' && in_array($this->groupMode(), ['public_full', 'public_limited', 'private'], true);
    }

    public function registrationMode(): string
    {
        return $this->normalizeMode((string) $this->config->get('user.auth.registration', 'closed'), ['open', 'invite', 'closed'], 'closed');
    }

    public function normalizeRoutePrefix(string $configured, string $fallback, bool $allowBlank = false): string
    {
        return PanelUrl::normalizeRoutePrefix($this->input, $configured, $fallback, $allowBlank);
    }

    public function resolveChannelRouteSeparator(string $channelValue): string
    {
        return ChannelRoutePolicy::resolveSeparator(
            $channelValue,
            (string) $this->config->get('content.route_separator', '-')
        );
    }

    public function globalPageRouteMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('content.route_mode', 'slug')));
        return in_array($mode, ['slug', 'id'], true) ? $mode : 'slug';
    }

    public function normalizeChannelRouteMode(string $value): string
    {
        return ChannelRoutePolicy::normalizeChannelRouteMode($value);
    }

    public function effectiveChannelRouteMode(string $channelValue): string
    {
        $mode = $this->normalizeChannelRouteMode($channelValue);
        return $mode === 'inherit' ? $this->globalPageRouteMode() : ChannelRoutePolicy::normalizeRouteMode($mode);
    }

    private function normalizeMode(string $value, array $allowed, string $fallback, array $aliases = []): string
    {
        $mode = strtolower(trim($value));
        if (isset($aliases[$mode])) {
            $mode = $aliases[$mode];
        }

        return in_array($mode, $allowed, true) ? $mode : $fallback;
    }
}
