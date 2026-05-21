<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scheduler/Queue.php
 * Standalone DB-backed queue operations for core scheduled jobs.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Scheduler;

use PDO;
use Raven\Lib\Database\SqlTable;

/**
 * Standalone queue operations for core scheduler jobs.
 *
 * Lives outside PageWrite so the public-route bootstrap and scheduler jobs
 * can trigger DB-writing queue operations without loading the full write-side
 * repository stack. All methods are stateless and accept the DB connection directly.
 */
final class Queue
{
    /**
     * Flips page statuses based on published / expires schedule columns.
     *
     * - Draft pages whose `published` timestamp is in the past become published.
     * - Published pages whose `expires` timestamp is in the past become draft.
     *
     * Safe to call repeatedly; only rows that actually need flipping are touched.
     * Called by the core `page-schedule` scheduler job registered in Raven.php.
     *
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     * @return void
     */
    public static function applySchedule(PDO $db, string $driver, string $prefix): void
    {
        $safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $pages = SqlTable::appTable($driver, $safePrefix, 'pages');
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
