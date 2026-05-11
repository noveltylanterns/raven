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
use Raven\Core\Debug\UniquenessProfiler;
use Raven\Core\Repository\ChannelShared;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Parser\ChannelParser;
use RuntimeException;

/**
 * INSERT and DELETE methods for redirect records.
 *
 * Read operations (SELECT, lookup) live in RedirectRead.
 */
final class RedirectWrite
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $channelRepo;

    /**
     * @param PDO         $db          Active database connection.
     * @param string      $driver      Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix      Table name prefix for this Raven installation.
     * @param ChannelRead $channelRepo Channel read instance used for slug resolution.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix, ChannelRead $channelRepo)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelRepo = $channelRepo;
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
        $redirects = $this->table('redirects');

        $id = $data['id'] ?? null;
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        $channelSlug = $data['channel_slug'] ?? null;
        $isActive = (int) ($data['active'] ?? 0) === 1 ? 1 : 0;
        $targetUrl = trim((string) ($data['target'] ?? ''));
        $channelId = $this->channelIdBySlug($channelSlug) ?? 0;
        if (trim((string) ($channelSlug ?? '')) !== '' && $channelId < 1) {
            throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
        }

        if ($title === '' || $slug === '' || $targetUrl === '') {
            throw new RuntimeException('Redirect title, slug, and target URL are required.');
        }

        // Redirect paths share the same uniqueness rule as pages: one slug per channel scope.
        if ($this->pathExists($slug, $channelId, $id)) {
            throw new RuntimeException('A redirect already exists for that slug/channel path.');
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($id !== null && $id > 0) {
            // Update in place so edit routes preserve existing redirect ids.
            $stmt = $this->db->prepare(
                'UPDATE ' . $redirects . '
                 SET title = :title,
                     description = :description,
                     slug = :slug,
                     channel = :channel,
                     active = :active,
                     target = :target,
                     updated = :updated
                 WHERE id = :id'
            );
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':slug' => $slug,
                ':channel' => $channelId,
                ':active' => $isActive,
                ':target' => $targetUrl,
                ':updated' => $now,
                ':id' => $id,
            ]);

            return $id;
        }

        // New redirect rows stamp both timestamps together to match existing panel inventory reads.
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $redirects . '
             (title, description, slug, channel, active, target, created, updated)
             VALUES (:title, :description, :slug, :channel, :active, :target, :created, :updated)'
        );
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':slug' => $slug,
            ':channel' => $channelId,
            ':active' => $isActive,
            ':target' => $targetUrl,
            ':created' => $now,
            ':updated' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deletes one redirect record by id.
     *
     * @param int $id Redirect id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $redirects = $this->table('redirects');
        $stmt = $this->db->prepare('DELETE FROM ' . $redirects . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Checks whether another redirect already claims the same channel-scoped path.
     *
     * @param string   $slug      Redirect slug/path segment.
     * @param int|null $channelId Resolved channel id, or null/0 for root scope.
     * @param int|null $ignoreId  Existing redirect id to exclude during edit mode.
     * @return bool True when the path is already claimed by another redirect.
     */
    private function pathExists(string $slug, ?int $channelId, ?int $ignoreId = null): bool
    {
        return UniquenessProfiler::exists(
            $this->db,
            $this->table('redirects'),
            $slug,
            $channelId,
            $ignoreId,
            'ignore_id',
            'channel'
        );
    }

    /**
     * Resolves one channel slug to its numeric id for channel-bound redirects.
     *
     * @param string|null $slug Submitted channel slug, or null/root sentinel.
     * @throws RuntimeException When a non-root channel slug does not resolve to a live channel.
     * @return int|null Resolved channel id, or `0` for root scope.
     */
    private function channelIdBySlug(?string $slug): ?int
    {
        if (ChannelShared::isRootChannelSlug((string) ($slug ?? ''))) {
            return 0;
        }

        return ChannelParser::resolveChannelIdBySlug(
            $slug,
            fn (string $normalized): ?int => $this->channelRepo->idBySlug($normalized),
            'Selected channel does not exist.'
        );
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string       Physical table name for the active backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
