<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/RedirectWrite.php
 * Write-side data access for URL redirect records (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Scribe\RedirectScribe;

/**
 * INSERT and DELETE methods for redirect records.
 *
 * Read operations (SELECT, lookup) live in RedirectRead.
 * RedirectScribe handles the actual SQL mutation and slug-path uniqueness checks.
 */
final class RedirectWrite
{
    private RedirectScribe $redirectScribe;

    /**
     * @param PDO         $db          Active database connection.
     * @param string      $driver      Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix      Table name prefix for this Raven installation.
     * @param ChannelRead $channelRepo Channel read instance passed through to RedirectScribe for slug resolution.
     */
    public function __construct(PDO $db, string $driver, string $prefix, ChannelRead $channelRepo)
    {
        $this->redirectScribe = new RedirectScribe($db, $driver, $prefix, $channelRepo);
    }

    /**
     * Creates or updates one redirect record and returns the redirect id.
     *
     * @param array{
     *   id: int|null,
     *   title: string,
     *   description: string,
     *   slug: string,
     *   channel_slug: string|null,
     *   active: int,
     *   target: string
     * } $data Normalized redirect fields.
     * @return int The saved redirect id.
     */
    public function save(array $data): int
    {
        return $this->redirectScribe->save($data);
    }

    /**
     * Deletes one redirect record by id.
     *
     * @param int $id Redirect id to delete.
     */
    public function deleteById(int $id): void
    {
        $this->redirectScribe->deleteById($id);
    }
}
