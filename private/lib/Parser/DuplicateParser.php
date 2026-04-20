<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/DuplicateParser.php
 * Shared (slug, channel) path-scope uniqueness lookup helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use PDO;

/**
 * Static helper for checking whether a slug already exists within a channel scope.
 *
 * Used by repositories to enforce slug uniqueness before inserting or updating page records.
 * Replaces the former PathScopeLookupService in lib/Routing/.
 */
final class DuplicateParser
{
    /**
     * Returns whether a slug exists within a given channel scope in a database table.
     *
     * Optionally excludes one record by id (e.g. when checking uniqueness during an update).
     *
     * @param PDO         $db                  Active database connection.
     * @param string      $table               Fully-qualified table name (already prefixed).
     * @param string      $slug                Slug value to check for conflicts.
     * @param int|null    $channelId           Channel id to scope the lookup; null or 0 targets the root channel.
     * @param int|null    $excludeId           Optional record id to exclude from the check (used on update).
     * @param string      $excludePlaceholder  Named placeholder for the exclude-id parameter (default 'exclude_id').
     * @param string      $channelColumn       Column name holding the channel reference (default 'channel').
     * @return bool                            True when a conflicting row already exists.
     */
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

        // Root channel (id 0 or null) pages are stored with channel = 0 or NULL.
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
