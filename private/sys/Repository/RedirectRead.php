<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/RedirectRead.php
 * Read-only data access for URL redirect records.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Core\Repository\ChannelRead;
use Raven\Lib\Database\SqlTable;

/**
 * SELECT and lookup methods for redirect records.
 *
 * Write operations (INSERT, UPDATE, DELETE) live in RedirectWrite.
 * Both panel listing and public-route redirect resolution live here since
 * redirects are read on every public request.
 */
class RedirectRead
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $channelRepo;

    /**
     * @param PDO         $db          Active database connection.
     * @param string      $driver      Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix      Table name prefix for this Raven installation.
     * @param ChannelRead $channelRepo Channel read instance for slug/id resolution and context hydration.
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
     * Returns all redirect rows with optional linked channel metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $redirects = $this->table('redirects');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
             FROM ' . $redirects . ' r
             ORDER BY r.updated DESC, r.id DESC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext($row, $channelsById);
        }

        return $rows;
    }

    /**
     * Returns total redirect count.
     *
     * @return int Total redirect row count.
     */
    public function count(): int
    {
        $redirects = $this->table('redirects');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $redirects);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated redirect rows with optional linked channel metadata.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array<int, array<string, mixed>>
     */
    public function listPaged(int $limit = 50, int $offset = 0): array
    {
        $redirects = $this->table('redirects');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
             FROM ' . $redirects . ' r
             ORDER BY r.updated DESC, r.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext($row, $channelsById);
        }

        return $rows;
    }

    /**
     * Returns one paginated redirect page plus total row count in one query.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0): array
    {
        $redirects = $this->table('redirects');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.title,
                    page_rows.description,
                    page_rows.slug,
                    page_rows.channel,
                    page_rows.active,
                    page_rows.target,
                    page_rows.created,
                    page_rows.updated,
                    totals.total_rows
             FROM (
                 SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
                 FROM ' . $redirects . ' r
                 ORDER BY r.updated DESC, r.id DESC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $redirects . '
             ) AS totals'
        );
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $total = 0;
        $resultRows = [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->withChannelContext($row, $channelsById);
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->count();
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns one redirect row by id.
     *
     * @param int $id Redirect id to resolve.
     * @return array<string, mixed>|null Redirect row with channel context, or null when not found.
     */
    public function findById(int $id): ?array
    {
        $redirects = $this->table('redirects');

        $stmt = $this->db->prepare(
            'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
             FROM ' . $redirects . ' r
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($row, $this->channelsByIdMap());
    }

    /**
     * Returns one redirect row by slug and optional channel scope.
     *
     * The repository accepts canonical selector values here: a normalized slug,
     * plus either a channel id, a normalized channel slug, or null for root scope.
     * Higher-level parser/controller/CLI layers are responsible for user-input
     * normalization before calling into this shared data boundary.
     *
     * @param string          $slug    Redirect slug to resolve.
     * @param int|string|null $channel Optional channel scope; null means root scope.
     * @return array<string, mixed>|null Redirect row with channel context, or null when not found.
     */
    public function findBySlug(string $slug, int|string|null $channel = null): ?array
    {
        $redirects = $this->table('redirects');
        $sql = 'SELECT r.id, r.title, r.description, r.slug, r.channel, r.active, r.target, r.created, r.updated
                FROM ' . $redirects . ' r
                WHERE r.slug = :slug';
        $params = [':slug' => $slug];

        if (is_string($channel)) {
            $channelId = $this->channelRepo->idBySlug($channel);
            if ($channelId === null || $channelId < 1) {
                return null;
            }

            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channelId;
        } elseif (is_int($channel) && $channel > 0) {
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channel;
        } else {
            $sql .= ' AND r.channel = 0';
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($row, $this->channelsByIdMap());
    }

    /**
     * Returns one redirect id by slug and optional channel scope, or null when not found.
     *
     * $channel accepts a channel ID (int), a channel slug (string), or null for root scope.
     * Root scope matches redirects that do not belong to any channel.
     *
     * @param string           $slug    Redirect slug to look up.
     * @param int|string|null  $channel Optional channel scope; null means root scope.
     * @return int|null Redirect id, or null when not found.
     */
    public function idBySlug(string $slug, int|string|null $channel = null): ?int
    {
        $redirects = $this->table('redirects');
        $sql = 'SELECT r.id FROM ' . $redirects . ' r WHERE r.slug = :slug';
        $params = [':slug' => $slug];

        if (is_string($channel)) {
            $channelId = $this->channelRepo->idBySlug($channel);
            if ($channelId === null || $channelId < 1) {
                return null;
            }
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channelId;
        } elseif (is_int($channel) && $channel > 0) {
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channel;
        } else {
            $sql .= ' AND r.channel = 0';
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Resolves one active redirect for public URL matching.
     *
     * Returns null when no active redirect exists for the given slug/channel combination.
     *
     * @param string      $slug        URL slug to match against redirect records.
     * @param string|null $channelSlug Optional channel slug scope; null matches root redirects.
     * @return array<string, mixed>|null Active redirect row with channel context, or null.
     */
    public function findActiveByPath(string $slug, ?string $channelSlug = null): ?array
    {
        $redirects = $this->table('redirects');

        $sql = 'SELECT r.id, r.title, r.slug, r.channel, r.target, r.active
                FROM ' . $redirects . ' r
                WHERE r.slug = :slug
                  AND r.active = :active';
        $params = [
            ':slug' => $slug,
            ':active' => 1,
        ];

        // Root redirects match only channelless rows; channel routes must match by id.
        if ($channelSlug === null) {
            $sql .= ' AND r.channel = 0';
        } else {
            $channelId = $this->channelRepo->idBySlug($channelSlug);
            if ($channelId === null || $channelId < 1) {
                return null;
            }
            $sql .= ' AND r.channel = :channel';
            $params[':channel'] = $channelId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($row, $this->channelsByIdMap());
    }

    /**
     * Returns all channel records indexed by id for O(1) context hydration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function channelsByIdMap(): array
    {
        return ChannelRead::channelsByIdMap($this->channelRepo->listRecords());
    }

    /**
     * Hydrates one redirect row with normalized types and channel metadata.
     *
     * @param array<string, mixed>          $row        Raw PDO redirect row.
     * @param array<int, array<string,mixed>> $channelsById Channel map keyed by id.
     * @return array<string, mixed> Hydrated row with channel context applied.
     */
    private function withChannelContext(array $row, array $channelsById): array
    {
        $channelId = (int) ($row['channel'] ?? 0);
        $channel = $channelId > 0 ? ($channelsById[$channelId] ?? null) : null;
        $row['channel'] = $channelId;
        $row['active'] = (int) ($row['active'] ?? 0);
        $row['target'] = (string) ($row['target'] ?? '');
        $row['created'] = (string) ($row['created'] ?? '');
        $row['updated'] = (string) ($row['updated'] ?? '');

        return ChannelRead::applyBasicChannelContext($row, $channel);
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
