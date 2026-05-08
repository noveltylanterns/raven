<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/UserPolicy.php
 * User profile routing configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;
use Raven\Core\Router\Public\PrefixResolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Config-backed routing policy helpers for user profiles.
 *
 * Reads user.* config keys so callers that only need user routing policy
 * do not have to construct a UserDataParser instance.
 */
final class UserPolicy
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * Initializes the user route-config reader.
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
     * Returns the normalized user-profile route prefix.
     *
     * @return string Route prefix slug (e.g. 'user').
     */
    public function profileRoutePrefix(): string
    {
        return PrefixResolver::normalize($this->input, (string) $this->config->get('user.prefix', 'user'), 'user', true);
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
     * Returns whether public profile routes are enabled system-wide.
     *
     * @return bool True when profiles have a prefix and are not fully disabled.
     */
    public function profileRouteEnabled(): bool
    {
        return $this->profileRoutePrefix() !== '' && in_array($this->profileMode(), ['public_full', 'public_limited', 'private'], true);
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
