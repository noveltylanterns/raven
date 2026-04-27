<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/ChannelPageRouter.php
 * Shared public channel-page route parsing and canonical segment policy.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Lib\Parser\ChannelRouteParser;
use Raven\Lib\Parser\PageRouteParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared public channel-page route parsing and canonical segment policy.
 *
 * A thin coordination surface that delegates all route normalization,
 * separator resolution, lookup-target resolution, and canonical segment
 * building to `Raven\Lib\Parser\ChannelRouteParser` and `Raven\Lib\Parser\PageRouteParser`,
 * keeping the calling controllers decoupled from the policy details.
 */
final class ChannelPageRouter
{
    private InputSanitizer $input;

    /**
     * @param InputSanitizer $input Shared text/path normalization helper.
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Normalizes a raw channel route-mode value to a canonical key.
     *
     * @param string $value Raw route-mode value from channel config.
     * @return string Canonical route-mode key (`slug`, `id`, `date`, or `inherit`).
     */
    public function normalizeRouteMode(string $value): string
    {
        return ChannelRouteParser::normalizeRouteMode($value);
    }

    /**
     * Resolves the effective word separator for a channel page route.
     *
     * @param string $channelValue Per-channel separator override value.
     * @param string $globalSeparator Site-wide global separator fallback.
     * @return string Resolved separator string.
     */
    public function resolveWordSeparator(string $channelValue, string $globalSeparator): string
    {
        return ChannelRouteParser::resolveSeparator($channelValue, $globalSeparator);
    }

    /**
     * Resolves one lookup target from a routed channel-page segment.
     *
     * @param string $requestedSlug URL segment from the incoming request.
     * @param string $routeMode Effective route mode for the channel.
     * @param string $wordSeparator Effective word separator for the channel.
     * @return array{type: 'slug', slug: string}|array{type: 'id', id: int}|null Lookup target, or null on parse failure.
     */
    public function resolveLookupTarget(string $requestedSlug, string $routeMode, string $wordSeparator): ?array
    {
        return PageRouteParser::resolveLookupTarget($this->input, $requestedSlug, $routeMode, $wordSeparator);
    }

    /**
     * Builds the canonical public URL segment for one channel page.
     *
     * @param string $slug Page slug.
     * @param int $pageId Page ID.
     * @param string $createdAt Page creation timestamp string.
     * @param string $routeMode Effective route mode for the channel.
     * @param string $wordSeparator Effective word separator for the channel.
     * @param string $globalSeparator Site-wide global separator fallback.
     * @return string Canonical URL segment for the page.
     */
    public function canonicalSegment(
        string $slug,
        int $pageId,
        string $createdAt,
        string $routeMode,
        string $wordSeparator,
        string $globalSeparator
    ): string {
        return PageRouteParser::buildRouteSegment(
            $this->input,
            $slug,
            $pageId,
            $createdAt,
            $routeMode,
            $wordSeparator,
            $globalSeparator
        );
    }
}
