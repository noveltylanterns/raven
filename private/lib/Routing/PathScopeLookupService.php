<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use PDO;

/**
 * Shared (slug, channel) path-scope uniqueness lookup helper.
 */
final class PathScopeLookupService
{
    public static function exists(
        PDO $db,
        string $table,
        string $slug,
        ?int $channelId,
        ?int $excludeId = null,
        string $excludePlaceholder = 'exclude_id',
        string $channelColumn = 'channel'
    ): bool {
        $excludePlaceholder = trim($excludePlaceholder);
        if ($excludePlaceholder === '') {
            $excludePlaceholder = 'exclude_id';
        }

        $sql = 'SELECT 1
                FROM ' . $table . '
                WHERE slug = :slug';
        $params = [':slug' => $slug];
        $channelColumn = trim($channelColumn);
        if ($channelColumn === '') {
            $channelColumn = 'channel';
        }

        if ($channelId === null || $channelId <= 0) {
            $sql .= ' AND (' . $channelColumn . ' = 0 OR ' . $channelColumn . ' IS NULL)';
        } else {
            $sql .= ' AND ' . $channelColumn . ' = :channel_id';
            $params[':channel_id'] = $channelId;
        }

        if ($excludeId !== null && $excludeId > 0) {
            $placeholder = ':' . $excludePlaceholder;
            $sql .= ' AND id <> ' . $placeholder;
            $params[$placeholder] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }
}
