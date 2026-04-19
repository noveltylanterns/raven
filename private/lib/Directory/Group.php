<?php

/**
 * RAVEN CMS
 * ~/private/lib/Directory/Group.php
 * User profile and group route-prefix configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Directory;

use Raven\Core\Config;
use Raven\Lib\Panel\PanelUrl;
use Raven\Lib\Security\InputSanitizer;

/**
 * Reads and normalizes user-profile and group routing configuration from site config.
 */
final class Group
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * Initializes the group route-config reader.
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
     * Returns the normalized user-profile route prefix.
     *
     * @return string Route prefix slug (e.g. 'user').
     */
    public function profileRoutePrefix(): string
    {
        return $this->normalizeRoutePrefix((string) $this->config->get('user.prefix', 'user'), 'user', true);
    }

    /**
     * Returns the normalized profile URL selector mode ('id', 'username', or 'string').
     *
     * Falls back to 'id' when the username selector is configured but login mode is not username-based.
     *
     * @return string Selector mode string.
     */
    public function profileSelector(): string
    {
        $selector = strtolower(trim((string) $this->config->get('user.selector', 'id')));
        if (!in_array($selector, ['id', 'username', 'string'], true)) {
            $selector = 'id';
        }

        // 'username' selector only works when login is also username-based.
        $loginMode = strtolower(trim((string) $this->config->get('user.auth.method', 'email')));
        if ($selector === 'username' && $loginMode !== 'username') {
            return 'id';
        }

        return $selector;
    }

    /**
     * Returns the normalized profile visibility mode.
     *
     * @return string One of 'public_full', 'public_limited', 'private', or 'disabled'.
     */
    public function profileMode(): string
    {
        return $this->normalizeMode(
            (string) $this->config->get('user.visibility', 'disabled'),
            ['public_full', 'public_limited', 'private', 'disabled'],
            'disabled'
        );
    }

    /**
     * Returns whether profile routes should appear in the routing table.
     *
     * @return bool True when profiles have a prefix and are not fully disabled.
     */
    public function profileRoutesEnabledForRoutingTable(): bool
    {
        return $this->profileRoutePrefix() !== '' && in_array($this->profileMode(), ['public_full', 'public_limited', 'private'], true);
    }

    /**
     * Returns the normalized group route prefix.
     *
     * @return string Route prefix slug (e.g. 'group').
     */
    public function groupRoutePrefix(): string
    {
        return $this->normalizeRoutePrefix((string) $this->config->get('group.prefix', 'group'), 'group', true);
    }

    /**
     * Returns the normalized group visibility mode.
     *
     * @return string One of 'public_full', 'public_limited', 'private', or 'disabled'.
     */
    public function groupMode(): string
    {
        return $this->normalizeMode(
            (string) $this->config->get('group.visibility', 'disabled'),
            ['public_full', 'public_limited', 'private', 'disabled'],
            'disabled',
            ['public' => 'public_full']
        );
    }

    /**
     * Returns whether group routes should appear in the routing table.
     *
     * @return bool True when groups have a prefix and are not fully disabled.
     */
    public function groupRoutesEnabledForRoutingTable(): bool
    {
        return $this->groupRoutePrefix() !== '' && in_array($this->groupMode(), ['public_full', 'public_limited', 'private'], true);
    }

    /**
     * Returns the normalized user registration mode.
     *
     * @return string One of 'open', 'invite', or 'closed'.
     */
    public function registrationMode(): string
    {
        return $this->normalizeMode(
            (string) $this->config->get('user.auth.registration', 'closed'),
            ['open', 'invite', 'closed'],
            'closed'
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
        return PanelUrl::normalizeRoutePrefix($this->input, $configured, $fallback, $allowBlank);
    }

    /**
     * Normalizes a mode string against an allowed list, applying optional aliases first.
     *
     * @param string               $value    Raw mode value from config.
     * @param array<int, string>   $allowed  Accepted mode strings.
     * @param string               $fallback Default to return when the value is not in the allowed list.
     * @param array<string,string> $aliases  Optional map of legacy aliases to canonical values.
     * @return string                        Validated mode string.
     */
    private function normalizeMode(string $value, array $allowed, string $fallback, array $aliases = []): string
    {
        $mode = strtolower(trim($value));
        if (isset($aliases[$mode])) {
            $mode = $aliases[$mode];
        }

        return in_array($mode, $allowed, true) ? $mode : $fallback;
    }
}
