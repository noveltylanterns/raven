<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared public channel-page route parsing and canonical segment policy.
 */
final class PublicChannelPageRouteService
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    public function normalizeRouteMode(string $value): string
    {
        return ChannelRoutePolicy::normalizeRouteMode($value);
    }

    public function resolveWordSeparator(string $channelValue, string $globalSeparator): string
    {
        return ChannelRoutePolicy::resolveSeparator($channelValue, $globalSeparator);
    }

    /**
     * Resolves one lookup slug from a routed channel-page segment.
     */
    public function resolveLookupSlug(string $requestedSlug, string $routeMode, string $wordSeparator): ?string
    {
        $routeMode = $this->normalizeRouteMode($routeMode);
        if ($routeMode === 'date_slug') {
            $parsed = ChannelRoutePolicy::parseDateSlugSegment($this->input, $requestedSlug, $wordSeparator);
            if (!is_array($parsed)) {
                return null;
            }

            return (string) ($parsed['slug'] ?? '');
        }

        return ChannelRoutePolicy::normalizeSlugForLookup($this->input, $requestedSlug, $wordSeparator);
    }

    public function canonicalSegment(
        string $slug,
        string $publishedAt,
        string $routeMode,
        string $wordSeparator,
        string $globalSeparator
    ): string {
        return ChannelRoutePolicy::buildRouteSegment(
            $this->input,
            $slug,
            $publishedAt,
            $routeMode,
            $wordSeparator,
            $globalSeparator
        );
    }
}
