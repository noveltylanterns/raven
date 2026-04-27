<?php
/**
 * RAVEN CMS
 * ~/private/lib/Parser/PageRepoParser.php
 * Stateless page-repository utility statics shared across read and write sides.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use PDO;
use Raven\Lib\Database\TableNameResolver;

/**
 * Shared page-repository primitives used by both PageRead and PageWrite.
 *
 * Lives in lib/Parser/ so neither the public-route bootstrap nor write-only panel
 * paths are forced to load the other side. Public routes call applySchedule() here
 * directly, which means PageWrite is never loaded on public requests.
 *
 * Do not add instance methods or request-context logic here.
 */
final class PageRepoParser
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
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Flips page statuses based on published / expires schedule columns.
     *
     * - Draft pages whose `published` timestamp is in the past become published.
     * - Published pages whose `expires` timestamp is in the past become draft.
     *
     * Designed to be called directly on every public request so that page state
     * is always up to date without loading PageWrite. Safe to call repeatedly;
     * only rows that actually need flipping are touched.
     *
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     */
    public static function applySchedule(PDO $db, string $driver, string $prefix): void
    {
        $safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $pages = TableNameResolver::appTable($driver, $safePrefix, 'pages');
        $now = gmdate('Y-m-d H:i:s');

        // Publish drafts whose scheduled publish time has arrived.
        $db->prepare(
            'UPDATE ' . $pages . '
             SET status = \'published\', updated = :now
             WHERE status = \'draft\'
               AND published IS NOT NULL
               AND published <= :now2'
        )->execute([':now' => $now, ':now2' => $now]);

        // Expire published pages whose expiry time has passed.
        $db->prepare(
            'UPDATE ' . $pages . '
             SET status = \'draft\', updated = :now
             WHERE status = \'published\'
               AND expires IS NOT NULL
               AND expires <= :now2'
        )->execute([':now' => $now, ':now2' => $now]);
    }
}
