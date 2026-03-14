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
        $mode = strtolower(trim((string) $this->config->get('user.privacy', 'disabled')));
        if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
            return 'disabled';
        }

        return $mode;
    }

    public function profileRoutesEnabledForRoutingTable(): bool
    {
        if ($this->profileRoutePrefix() === '') {
            return false;
        }

        return in_array($this->profileMode(), ['public_full', 'public_limited', 'private'], true);
    }

    public function groupRoutePrefix(): string
    {
        return $this->normalizeRoutePrefix((string) $this->config->get('group.prefix', 'group'), 'group', true);
    }

    public function groupMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('group.privacy', 'disabled')));
        if ($mode === 'public') {
            $mode = 'public_full';
        }
        if (!in_array($mode, ['public_full', 'public_limited', 'private', 'disabled'], true)) {
            return 'disabled';
        }

        return $mode;
    }

    public function groupRoutesEnabledForRoutingTable(): bool
    {
        if ($this->groupRoutePrefix() === '') {
            return false;
        }

        return in_array($this->groupMode(), ['public_full', 'public_limited', 'private'], true);
    }

    public function registrationMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('user.auth.registration', 'closed')));
        if (!in_array($mode, ['open', 'invite', 'closed'], true)) {
            return 'closed';
        }

        return $mode;
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
}
