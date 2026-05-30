<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageShared.php
 * Stateless page-repository utility statics shared across read and write sides.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

/**
 * Shared page-repository primitives used by both PageRead and PageWrite.
 *
 * Do not add instance methods or request-context logic here.
 */
final class PageShared
{
    /**
     * Normalizes an id list into unique positive integers.
     *
     * Accepts any mixed array input; non-positive or non-integer-castable values are
     * silently dropped. Used by both PageRead (taxonomy assignment queries) and PageWrite
     * (category/tag id normalization before save).
     *
     * @param mixed $ids Raw id array from any caller.
     * @return array<int> Deduplicated, sorted array of positive integer ids.
     */
    public static function normalizeIds(mixed $ids): array
    {
        // Mixed caller payloads are normalized by rejecting non-array inputs early.
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        // Cast each candidate id and use the value as the key for de-duplication.
        foreach ($ids as $id) {
            $value = (int) $id;
            // Only positive ids represent valid persisted taxonomy references.
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }
}
