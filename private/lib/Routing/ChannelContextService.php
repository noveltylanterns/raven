<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use RuntimeException;

/**
 * Shared helpers for channel id lookup and row context hydration.
 */
final class ChannelContextService
{
    /**
     * @param array<int, array<string, mixed>> $channelRecords
     * @return array<int, array<string, mixed>>
     */
    public static function channelsByIdMap(array $channelRecords): array
    {
        $map = [];
        foreach ($channelRecords as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $id = (int) ($channel['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $map[$id] = $channel;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $channel
     * @return array<string, mixed>
     */
    public static function applyBasicChannelContext(array $row, ?array $channel): array
    {
        $row['channel_slug'] = $channel !== null ? (string) ($channel['slug'] ?? '') : '';
        $row['channel_name'] = $channel !== null ? (string) ($channel['name'] ?? '') : '';

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $channel
     * @return array<string, mixed>
     */
    public static function applyPageChannelContext(array $row, ?array $channel): array
    {
        $row = self::applyBasicChannelContext($row, $channel);
        $row['route_mode_effective'] = $channel !== null
            ? (string) ($channel['route_mode'] ?? 'inherit')
            : 'inherit';
        $row['route_separator_effective'] = $channel !== null
            ? (string) ($channel['route_separator'] ?? 'inherit')
            : 'inherit';

        return $row;
    }

    public static function resolveChannelIdBySlug(
        ?string $slug,
        callable $idBySlugResolver,
        string $missingMessage = 'Selected channel does not exist.'
    ): ?int {
        $normalized = strtolower(trim((string) ($slug ?? '')));
        if ($normalized === '') {
            return null;
        }

        $resolved = $idBySlugResolver($normalized);
        $id = $resolved !== null ? (int) $resolved : null;
        if ($id === null || $id < 1) {
            throw new RuntimeException($missingMessage);
        }

        return $id;
    }
}
