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
        return $this->configBool($this->config->get('category.enabled', true), true);
    }

    public function tagEnabled(): bool
    {
        return $this->configBool($this->config->get('tag.enabled', true), true);
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

    public function resolveChannelPageUrlSeparator(string $channelValue): string
    {
        return ChannelRoutePolicy::resolveSeparator(
            $channelValue,
            (string) $this->config->get('content.separator', '-')
        );
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
