<?php

declare(strict_types=1);

namespace Raven\Lib\Routing\Public;

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
     * Resolves one lookup target from a routed channel-page segment.
     *
     * @return array{type: 'slug', slug: string}|array{type: 'id', id: int}|null
     */
    public function resolveLookupTarget(string $requestedSlug, string $routeMode, string $wordSeparator): ?array
    {
        return ChannelRoutePolicy::resolveLookupTarget($this->input, $requestedSlug, $routeMode, $wordSeparator);
    }

    public function canonicalSegment(
        string $slug,
        int $pageId,
        string $createdAt,
        string $routeMode,
        string $wordSeparator,
        string $globalSeparator
    ): string {
        return ChannelRoutePolicy::buildRouteSegment(
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
