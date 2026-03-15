<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use PDO;

/**
 * Shared (slug, channel_id) path-scope uniqueness lookup helper.
 */
final class PathScopeLookupService
{
    public static function exists(
        PDO $db,
        string $table,
        string $slug,
        ?int $channelId,
        ?int $excludeId = null,
        string $excludePlaceholder = 'exclude_id'
    ): bool {
        $excludePlaceholder = trim($excludePlaceholder);
        if ($excludePlaceholder === '') {
            $excludePlaceholder = 'exclude_id';
        }

        $sql = 'SELECT 1
                FROM ' . $table . '
                WHERE slug = :slug';
        $params = [':slug' => $slug];

        if ($channelId === null) {
            $sql .= ' AND channel_id IS NULL';
        } else {
            $sql .= ' AND channel_id = :channel_id';
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
