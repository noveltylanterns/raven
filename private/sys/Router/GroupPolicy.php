<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/GroupPolicy.php
 * Group routing configuration helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Router;

use Raven\Core\Config;
use Raven\Core\Router\Public\PrefixResolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Config-backed routing policy helpers for groups.
 *
 * Reads group.* config keys so callers that only need group routing policy
 * do not have to construct a GroupDataParser instance.
 */
final class GroupPolicy
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * Initializes the group route-config reader.
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
     * Returns the normalized group route prefix.
     *
     * @return string Route prefix slug (e.g. 'group').
     */
    public function groupRoutePrefix(): string
    {
        return PrefixResolver::normalize($this->input, (string) $this->config->get('group.prefix', 'group'), 'group', true);
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
