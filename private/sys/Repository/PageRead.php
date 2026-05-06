<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageRead.php
 * Read-only data access for page records, public listing, and taxonomy filter queries.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Parser\ChannelRepoParser;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\PageRepoParser;
use Raven\Lib\Database\TableNameResolver;

/**
 * SELECT and lookup methods for pages, public listings, and taxonomy filters.
 *
 * Write operations (save, deleteById) live in PageWrite.
 * Schedule flipping (applySchedule) lives in PageRepoParser so public routes
 * can call it without loading this class or PageWrite.
 */
class PageRead
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $channelRepo;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PageBlockParser $pageBlockParser;

    /**
     * @param PDO         $db               Active database connection.
     * @param string      $driver           Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix           Table name prefix for this Raven installation.
     * @param ChannelRead $channelRepo      Channel read-side repository for channel resolution.
     * @param bool        $categoryEnabled  Whether category taxonomy support is active.
     * @param bool        $tagEnabled       Whether tag taxonomy support is active.
     */
    public function __construct(
        PDO $db,
        string $driver,
        string $prefix,
        ChannelRead $channelRepo,
        bool $categoryEnabled = true,
        bool $tagEnabled = true
    ) {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelRepo = $channelRepo;
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->pageBlockParser = new PageBlockParser();
    }

    /**
     * Finds the root homepage by slug priority: `home` first, then `index`.
     *
     * Page must not belong to any channel.
     *
     * @return array<string, mixed>|null Hydrated page row, or null when no root homepage is published.
     */
    public function findHomepage(): ?array
    {
        $pages = $this->table('pages');

        // CASE ordering guarantees `home` wins over `index` when both exist.
        $stmt = $this->db->prepare(
            'SELECT p.*
             FROM ' . $pages . ' p
             WHERE p.channel = 0
               AND p.status = :status
               AND p.slug IN (:slug_home, :slug_index)
             ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                      p.created DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'published',
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->hydratePageRow($row);
    }

    /**
     * Finds the channel homepage by slug priority: `home` first, then `index`.
     *
     * Returns the resolved channel and its homepage as a named-key tuple so the
     * caller can reuse the already-fetched channel row without a second DB round-trip.
     * Returns null when the channel slug does not resolve to a known channel.
     * When the channel exists but has no published homepage, `page` is null.
     *
     * @param string $channelSlug Normalized channel slug to look up.
     * @return array{channel: array<string,mixed>, page: ?array<string,mixed>}|null Null when channel not found.
     */
    public function findChannelHomepage(string $channelSlug): ?array
    {
        $pages = $this->table('pages');
        $channel = $this->channelRepo->findBySlug($channelSlug);
        if ($channel === null) {
            return null;
        }

        $channelId = (int) ($channel['id'] ?? 0);
        if ($channelId < 1) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT p.*
             FROM ' . $pages . ' p
             WHERE p.channel = :channel
               AND p.status = :status
               AND p.slug IN (:slug_home, :slug_index)
             ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                      p.created DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':channel' => $channelId,
            ':status' => 'published',
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $row = $stmt->fetch();

        // Return the channel alongside the page so callers avoid a second lookup.
        // 'page' is null when no published homepage exists for this channel.
        return [
            'channel' => $channel,
            'page'    => $row !== false ? $this->withChannelContext($this->hydratePageRow($row), $channel) : null,
        ];
    }

    /**
     * Finds one published page by slug and optional channel slug.
     *
     * Unchanneled pages resolve at root; channeled pages require an explicit channel slug match.
     *
     * @param string      $pageSlug    Exact page slug to look up.
     * @param string|null $channelSlug Optional channel slug to scope the lookup.
     * @return array<string, mixed>|null Hydrated page row with channel context, or null when not found.
     */
    public function findPublishedBySlug(string $pageSlug, ?string $channelSlug = null): ?array
    {
        $pages = $this->table('pages');
        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.slug = :page_slug
                  AND p.status = :status';
        $params = [
            ':page_slug' => $pageSlug,
            ':status' => 'published',
        ];

        $channel = null;
        if ($channelSlug === null) {
            $sql .= ' AND p.channel = 0';
        } else {
            $channel = $this->channelRepo->findBySlug($channelSlug);
            if ($channel === null) {
                return null;
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                return null;
            }

            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channelId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        return $row === false ? null : $this->withChannelContext($this->hydratePageRow($row), $channel);
    }

    /**
     * Finds one published page by id and optional channel slug.
     *
     * @param int         $pageId      Page id to resolve.
     * @param string|null $channelSlug Optional channel slug to scope the lookup.
     * @return array<string, mixed>|null Hydrated page row with channel context, or null when not found.
     */
    public function findPublishedById(int $pageId, ?string $channelSlug = null): ?array
    {
        if ($pageId < 1) {
            return null;
        }

        $pages = $this->table('pages');
        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.id = :page_id
                  AND p.status = :status';
        $params = [
            ':page_id' => $pageId,
            ':status' => 'published',
        ];

        $channel = null;
        if ($channelSlug === null) {
            $sql .= ' AND p.channel = 0';
        } else {
            $channel = $this->channelRepo->findBySlug($channelSlug);
            if ($channel === null) {
                return null;
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                return null;
            }

            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channelId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        return $row === false ? null : $this->withChannelContext($this->hydratePageRow($row), $channel);
    }

    /**
     * Returns one page by slug and optional channel scope.
     *
     * $channel accepts a channel id (int), a channel slug (string), or null for root scope.
     * Root scope matches pages that do not belong to any channel.
     *
     * @param string           $pageSlug Exact page slug to look up.
     * @param int|string|null  $channel  Channel id, slug, or null for root.
     * @return array<string, mixed>|null Hydrated page row, or null when not found.
     */
    public function findBySlug(string $pageSlug, int|string|null $channel = null): ?array
    {
        $pages = $this->table('pages');
        $sql = 'SELECT p.* FROM ' . $pages . ' p WHERE p.slug = :slug';
        $params = [':slug' => $pageSlug];

        if (is_string($channel)) {
            $channelId = $this->channelRepo->idFromSlug($channel);
            if ($channelId < 1) {
                return null;
            }
            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channelId;
        } elseif (is_int($channel) && $channel > 0) {
            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channel;
        } else {
            // null or 0 = root scope
            $sql .= ' AND p.channel = 0';
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        return $row === false ? null : $this->hydratePageRow($row);
    }

    /**
     * Returns one page id by slug and optional channel scope, or null when not found.
     *
     * $channel accepts a channel id (int), a channel slug (string), or null for root scope.
     *
     * @param string           $pageSlug Exact page slug to look up.
     * @param int|string|null  $channel  Channel id, slug, or null for root.
     * @return int|null Matching page id, or null when not found.
     */
    public function idBySlug(string $pageSlug, int|string|null $channel = null): ?int
    {
        $pages = $this->table('pages');
        $sql = 'SELECT p.id FROM ' . $pages . ' p WHERE p.slug = :slug';
        $params = [':slug' => $pageSlug];

        if (is_string($channel)) {
            $channelId = $this->channelRepo->idFromSlug($channel);
            if ($channelId < 1) {
                return null;
            }
            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channelId;
        } elseif (is_int($channel) && $channel > 0) {
            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channel;
        } else {
            $sql .= ' AND p.channel = 0';
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /**
     * Returns newest published pages, optionally scoped to one channel slug.
     *
     * Channel scope values:
     * - `null` / `''`: all channels
     * - `root`: root-scope pages only
     * - any other slug: only that channel
     *
     * @param int         $limit       Maximum number of rows to return.
     * @param string|null $channelSlug Optional channel slug to scope results.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listRecentPublished(int $limit, ?string $channelSlug = null): array
    {
        $safeLimit = max(1, $limit);
        $normalizedChannelSlug = strtolower(trim((string) ($channelSlug ?? '')));
        $pages = $this->table('pages');
        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.status = :status';
        $params = [':status' => 'published'];

        $channel = null;
        if ($normalizedChannelSlug === ChannelRepoParser::ROOT_CHANNEL_SLUG) {
            $sql .= ' AND p.channel = 0';
        } elseif ($normalizedChannelSlug !== '') {
            $channel = $this->channelRepo->findBySlug($normalizedChannelSlug);
            if ($channel === null) {
                return [];
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                return [];
            }

            $sql .= ' AND p.channel = :channel';
            $params[':channel'] = $channelId;
        }

        $sql .= '
                ORDER BY p.created DESC, p.id DESC
                LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $normalizedChannelSlug === '' ? $this->channelsByIdMap() : null;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext(
                $this->hydratePageRow($row),
                is_array($channel) ? $channel : null,
                $channelsById
            );
        }

        return $rows;
    }

    /**
     * Returns newest published pages scoped to an explicit list of channel slugs.
     *
     * @param int            $limit        Maximum number of rows to return.
     * @param array<int, string> $channelSlugs Channel slugs to restrict the query to.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listRecentPublishedForChannels(int $limit, array $channelSlugs): array
    {
        $safeLimit = max(1, $limit);
        $normalizedSlugs = [];
        foreach ($channelSlugs as $channelSlug) {
            $normalized = strtolower(trim((string) $channelSlug));
            if ($normalized === '') {
                continue;
            }

            $normalizedSlugs[$normalized] = $normalized;
        }

        if ($normalizedSlugs === []) {
            return [];
        }

        $pages = $this->table('pages');
        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.status = :status';
        $params = [':status' => 'published'];

        $clauses = [];
        $includeRoot = isset($normalizedSlugs[ChannelRepoParser::ROOT_CHANNEL_SLUG]);
        unset($normalizedSlugs[ChannelRepoParser::ROOT_CHANNEL_SLUG]);

        if ($includeRoot) {
            $clauses[] = 'p.channel = 0';
        }

        $channelIds = [];
        foreach ($normalizedSlugs as $normalizedSlug) {
            $channel = $this->channelRepo->findBySlug($normalizedSlug);
            if (!is_array($channel)) {
                continue;
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                continue;
            }

            $channelIds[$channelId] = $channelId;
        }

        if ($channelIds !== []) {
            $placeholders = [];
            $index = 0;
            foreach ($channelIds as $channelId) {
                $placeholder = ':channel_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $channelId;
                $index++;
            }

            $clauses[] = 'p.channel IN (' . implode(', ', $placeholders) . ')';
        }

        if ($clauses === []) {
            return [];
        }

        $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
        $sql .= '
                ORDER BY p.created DESC, p.id DESC
                LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext($this->hydratePageRow($row), null, $channelsById);
        }

        return $rows;
    }

    /**
     * Returns total page count with optional prefilters.
     *
     * @param int|null $channelId  Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter.
     * @param int|null $tagId      Optional tag id filter.
     * @return int Total matching page count.
     */
    public function count(?int $channelId = null, ?int $categoryId = null, ?int $tagId = null): int
    {
        $pages = $this->table('pages');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');

        $where = ['1 = 1'];
        $params = [];
        $this->appendFilterClauses(
            $where,
            $params,
            $channelId,
            $categoryId,
            $tagId,
            $pageCategories,
            $pageTags,
            'filter',
            $this->categoryEnabled,
            $this->tagEnabled
        );

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pages . ' p
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated page list with optional prefilters.
     *
     * @param int      $limit      Maximum number of rows to return.
     * @param int      $offset     Zero-based row offset for pagination.
     * @param int|null $channelId  Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter.
     * @param int|null $tagId      Optional tag id filter.
     * @return array<int, array<string, mixed>> Paginated page rows with channel context.
     */
    public function listPaged(
        int $limit = 50,
        int $offset = 0,
        ?int $channelId = null,
        ?int $categoryId = null,
        ?int $tagId = null
    ): array {
        $pages = $this->table('pages');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');

        $where = ['1 = 1'];
        $params = [];
        $this->appendFilterClauses(
            $where,
            $params,
            $channelId,
            $categoryId,
            $tagId,
            $pageCategories,
            $pageTags,
            'filter',
            $this->categoryEnabled,
            $this->tagEnabled
        );

        $stmt = $this->db->prepare(
            'SELECT p.id, p.title, p.slug, p.status, p.created, p.channel
             FROM ' . $pages . ' p
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.created DESC
             LIMIT :limit OFFSET :offset'
        );

        // Bind as ints to avoid backend-specific LIMIT/OFFSET casting quirks.
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $channelsById = $this->channelsByIdMap();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->withChannelContext($row, null, $channelsById);
        }

        return $rows;
    }

    /**
     * Returns one paginated page-list page plus total row count.
     *
     * @param int      $limit      Maximum number of rows to return.
     * @param int      $offset     Zero-based row offset for pagination.
     * @param int|null $channelId  Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter.
     * @param int|null $tagId      Optional tag id filter.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(
        int $limit = 50,
        int $offset = 0,
        ?int $channelId = null,
        ?int $categoryId = null,
        ?int $tagId = null
    ): array {
        $pages = $this->table('pages');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $pageWhere = ['1 = 1'];
        $pageParams = [];
        $this->appendFilterClauses(
            $pageWhere,
            $pageParams,
            $channelId,
            $categoryId,
            $tagId,
            $pageCategories,
            $pageTags,
            'page_filter',
            $this->categoryEnabled,
            $this->tagEnabled
        );

        $countWhere = ['1 = 1'];
        $countParams = [];
        $this->appendFilterClauses(
            $countWhere,
            $countParams,
            $channelId,
            $categoryId,
            $tagId,
            $pageCategories,
            $pageTags,
            'count_filter',
            $this->categoryEnabled,
            $this->tagEnabled
        );

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.title,
                    page_rows.slug,
                    page_rows.status,
                    page_rows.created,
                    page_rows.channel,
                    totals.total_rows
             FROM (
                 SELECT p.id, p.title, p.slug, p.status, p.created, p.channel
                 FROM ' . $pages . ' p
                 WHERE ' . implode(' AND ', $pageWhere) . '
                 ORDER BY p.created DESC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $pages . ' p
                 WHERE ' . implode(' AND ', $countWhere) . '
             ) AS totals'
        );

        foreach ($pageParams as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        foreach ($countParams as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
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
            $resultRows[] = $this->withChannelContext($row, null, $channelsById);
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->count($channelId, $categoryId, $tagId);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns all pages with channel context for routing inventory screens.
     *
     * @return array<int, array<string, mixed>> Flat page rows with id, title, slug, status, created, channel.
     */
    public function listAllForRouting(): array
    {
        $pages = $this->table('pages');

        $stmt = $this->db->prepare(
            'SELECT p.id, p.title, p.slug, p.status, p.created, p.channel
             FROM ' . $pages . ' p
             ORDER BY COALESCE(p.channel, 0) ASC, p.slug ASC, p.id ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns one landing-page slug map keyed by channel slug for routing inventory.
     *
     * Landing priority per channel: `home` first, fallback `index`.
     *
     * @return array<string, string> Channel slug to homepage slug map; empty string means no homepage found.
     */
    public function channelHomepagesForRouting(): array
    {
        $pages = $this->table('pages');
        $channelsById = $this->channelsByIdMap();
        if ($channelsById === []) {
            return [];
        }

        $result = [];
        foreach ($channelsById as $channelId => $channel) {
            $slug = trim((string) ($channel['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $result[$slug] = '';
        }

        $stmt = $this->db->prepare(
            'SELECT p.channel, p.slug
             FROM ' . $pages . ' p
             WHERE p.channel IS NOT NULL
               AND p.channel <> 0
               AND p.status = :status
               AND p.slug IN (:slug_home, :slug_index)
             ORDER BY p.channel ASC,
                      CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                      p.created DESC'
        );
        $stmt->execute([
            ':status' => 'published',
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $seen = [];
        foreach ($rows as $row) {
            $channelId = (int) ($row['channel'] ?? 0);
            if ($channelId < 1 || isset($seen[$channelId]) || !isset($channelsById[$channelId])) {
                continue;
            }

            $channelSlug = trim((string) ($channelsById[$channelId]['slug'] ?? ''));
            if ($channelSlug === '') {
                continue;
            }

            $result[$channelSlug] = trim((string) ($row['slug'] ?? ''));
            $seen[$channelId] = true;
        }

        return $result;
    }

    /**
     * Returns one page by id with channel context.
     *
     * @param int $id Page id to resolve.
     * @return array<string, mixed>|null Hydrated page row with channel context, or null when not found.
     */
    public function findById(int $id): ?array
    {
        $pages = $this->table('pages');

        $stmt = $this->db->prepare(
            'SELECT p.*
             FROM ' . $pages . ' p
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->withChannelContext($this->hydratePageRow($row));
    }

    /**
     * Returns the hydrated page row and raw image/variant join rows for the page editor.
     *
     * Gallery image hydration is the caller's responsibility; pass `gallery_rows` to
     * `EditorMedia::hydrate()` to produce the final `gallery_images` array.
     *
     * @param int $id Page id to load.
     * @return array{page: array<string, mixed>, gallery_rows: array<int, array<string, mixed>>}|null Null when not found.
     */
    public function findByIdWithGalleryRows(int $id): ?array
    {
        $pages = $this->table('pages');
        $images = $this->table('media');
        $variants = $this->table('media_variants');

        $stmt = $this->db->prepare(
            'SELECT
                p.*,
                i.id AS image_id,
                i.page AS image_page_id,
                i.storage_target AS image_storage_target,
                i.original_filename AS image_original_filename,
                i.stored_filename AS image_stored_filename,
                i.stored_path AS image_stored_path,
                i.mime_type AS image_mime_type,
                i.extension AS image_extension,
                i.byte_size AS image_byte_size,
                i.width AS image_width,
                i.height AS image_height,
                i.hash AS image_hash_sha256,
                i.status AS image_status,
                i.sort_order AS image_sort_order,
                CASE WHEN p.cover_image IS NOT NULL AND p.cover_image = i.id THEN 1 ELSE 0 END AS image_is_cover,
                i.include_in_gallery AS image_include_in_gallery,
                i.alt_text AS image_alt_text,
                i.title_text AS image_title_text,
                i.caption AS image_caption,
                i.credit AS image_credit,
                i.license AS image_license,
                i.focal_x AS image_focal_x,
                i.focal_y AS image_focal_y,
                i.created AS image_created,
                i.updated AS image_updated,
                v.variant_key AS variant_key,
                v.stored_filename AS variant_stored_filename,
                v.stored_path AS variant_stored_path,
                v.mime_type AS variant_mime_type,
                v.extension AS variant_extension,
                v.byte_size AS variant_byte_size,
                v.width AS variant_width,
                v.height AS variant_height
             FROM ' . $pages . ' p
             LEFT JOIN ' . $images . ' i ON i.page = p.id
             LEFT JOIN ' . $variants . ' v ON v.image = i.id
             WHERE p.id = :id
             ORDER BY i.sort_order ASC, i.id ASC, v.variant_key ASC'
        );
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return null;
        }

        // Strip image/variant join columns before hydrating the page row.
        $pageRow = $rows[0];
        foreach (array_keys($pageRow) as $col) {
            $col = (string) $col;
            if (str_starts_with($col, 'image_') || str_starts_with($col, 'variant_')) {
                unset($pageRow[$col]);
            }
        }

        return [
            'page'         => $this->withChannelContext($this->hydratePageRow($pageRow)),
            'gallery_rows' => $rows,
        ];
    }

    /**
     * Returns assigned categories for one page.
     *
     * @param int $pageId Page id to query assigned categories for.
     * @return array<int, array{id: int, name: string, slug: string}> Category rows assigned to the page.
     */
    public function assignedCategoryRowsForPage(int $pageId): array
    {
        if (!$this->categoryEnabled) {
            return [];
        }

        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug
             FROM ' . $pageCategories . ' pc
             INNER JOIN ' . $categories . ' c ON c.id = pc.category
             WHERE pc.page = :page
             ORDER BY c.name ASC, c.id ASC'
        );
        $stmt->execute([':page' => $pageId]);

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $result;
    }

    /**
     * Returns assigned tags for one page.
     *
     * @param int $pageId Page id to query assigned tags for.
     * @return array<int, array{id: int, name: string, slug: string}> Tag rows assigned to the page.
     */
    public function assignedTagRowsForPage(int $pageId): array
    {
        if (!$this->tagEnabled) {
            return [];
        }

        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.slug
             FROM ' . $pageTags . ' pt
             INNER JOIN ' . $tags . ' t ON t.id = pt.tag
             WHERE pt.page = :page
             ORDER BY t.name ASC, t.id ASC'
        );
        $stmt->execute([':page' => $pageId]);

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $result;
    }

    /**
     * Returns category/tag assignment ids grouped by page id.
     *
     * @param array<int> $pageIds Page ids to query taxonomy assignments for.
     * @return array<int, array{categories: array<int>, tags: array<int>}> Assignments keyed by page id.
     */
    public function taxonomyAssignmentIdsByPage(array $pageIds): array
    {
        $normalizedPageIds = PageRepoParser::normalizeIds($pageIds);
        if ($normalizedPageIds === []) {
            return [];
        }

        $result = [];
        foreach ($normalizedPageIds as $pageId) {
            $result[$pageId] = [
                'categories' => [],
                'tags' => [],
            ];
        }

        if (!$this->categoryEnabled && !$this->tagEnabled) {
            return $result;
        }

        $unionQueries = [];
        $params = [];
        if ($this->categoryEnabled) {
            $categoryPlaceholders = implode(', ', array_fill(0, count($normalizedPageIds), '?'));
            $pageCategories = $this->table('page_categories');
            $unionQueries[] =
                'SELECT page AS page_id, category AS taxonomy_id, \'category\' AS taxonomy_type
                 FROM ' . $pageCategories . '
                 WHERE page IN (' . $categoryPlaceholders . ')';
            $params = array_merge($params, $normalizedPageIds);
        }
        if ($this->tagEnabled) {
            $tagPlaceholders = implode(', ', array_fill(0, count($normalizedPageIds), '?'));
            $pageTags = $this->table('page_tags');
            $unionQueries[] =
                'SELECT page AS page_id, tag AS taxonomy_id, \'tag\' AS taxonomy_type
                 FROM ' . $pageTags . '
                 WHERE page IN (' . $tagPlaceholders . ')';
            $params = array_merge($params, $normalizedPageIds);
        }

        $assignmentStmt = $this->db->prepare(
            'SELECT page_id, taxonomy_id, taxonomy_type
             FROM (
                 ' . implode("\n                 UNION ALL\n                 ", $unionQueries) . '
             ) AS assignment_rows'
        );
        $assignmentStmt->execute($params);
        foreach ($assignmentStmt->fetchAll() ?: [] as $row) {
            $pageId = (int) ($row['page_id'] ?? 0);
            $taxonomyId = (int) ($row['taxonomy_id'] ?? 0);
            $taxonomyType = strtolower(trim((string) ($row['taxonomy_type'] ?? '')));
            if ($pageId < 1 || $taxonomyId < 1 || !isset($result[$pageId])) {
                continue;
            }

            if ($taxonomyType === 'category') {
                $result[$pageId]['categories'][$taxonomyId] = $taxonomyId;
                continue;
            }

            if ($taxonomyType === 'tag') {
                $result[$pageId]['tags'][$taxonomyId] = $taxonomyId;
            }
        }

        foreach ($result as $pageId => $assignments) {
            $result[$pageId]['categories'] = array_values($assignments['categories']);
            $result[$pageId]['tags'] = array_values($assignments['tags']);
        }

        return $result;
    }

    /**
     * Returns paginated pages for one category slug ordered newest-first.
     *
     * @param string $slug   Normalized category slug to query.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listByCategorySlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled) {
            return [];
        }

        return $this->listTaxonomyPagesBySlug($this->table('categories'), $this->table('page_categories'), 'category', $slug, $limit, $offset);
    }

    /**
     * Counts total pages linked to a category slug.
     *
     * @param string $slug Normalized category slug to count pages for.
     * @return int Published page count for this category.
     */
    public function countByCategorySlug(string $slug): int
    {
        if (!$this->categoryEnabled) {
            return 0;
        }

        return $this->countTaxonomyPagesBySlug($this->table('categories'), $this->table('page_categories'), 'category', $slug);
    }

    /**
     * Returns paginated pages for one tag slug ordered newest-first.
     *
     * @param string $slug   Normalized tag slug to query.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listByTagSlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->tagEnabled) {
            return [];
        }

        return $this->listTaxonomyPagesBySlug($this->table('tags'), $this->table('page_tags'), 'tag', $slug, $limit, $offset);
    }

    /**
     * Counts total pages linked to a tag slug.
     *
     * @param string $slug Normalized tag slug to count pages for.
     * @return int Published page count for this tag.
     */
    public function countByTagSlug(string $slug): int
    {
        if (!$this->tagEnabled) {
            return 0;
        }

        return $this->countTaxonomyPagesBySlug($this->table('tags'), $this->table('page_tags'), 'tag', $slug);
    }

    /**
     * Returns one paginated category-page result with total count.
     *
     * @param string $slug   Normalized category slug to query.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByCategorySlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->listTaxonomyPagedBySlug(
            $this->table('categories'),
            $this->table('page_categories'),
            'category',
            $slug,
            $limit,
            $offset,
            fn (): int => $this->countByCategorySlug($slug)
        );
    }

    /**
     * Returns one paginated tag-page result with total count.
     *
     * @param string $slug   Normalized tag slug to query.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByTagSlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->tagEnabled) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->listTaxonomyPagedBySlug(
            $this->table('tags'),
            $this->table('page_tags'),
            'tag',
            $slug,
            $limit,
            $offset,
            fn (): int => $this->countByTagSlug($slug)
        );
    }

    /**
     * Returns paginated pages for one category id ordered newest-first.
     *
     * @param int $categoryId Category id to scope results to.
     * @param int $limit      Maximum rows to return.
     * @param int $offset     Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listByCategoryId(int $categoryId, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled || $categoryId < 1) {
            return [];
        }

        return $this->listTaxonomyPagesById($this->table('page_categories'), 'category', $categoryId, $limit, $offset);
    }

    /**
     * Counts total pages linked to a category id.
     *
     * @param int $categoryId Category id to count pages for.
     * @return int Published page count for this category.
     */
    public function countByCategoryId(int $categoryId): int
    {
        if (!$this->categoryEnabled || $categoryId < 1) {
            return 0;
        }

        return $this->countTaxonomyPagesById($this->table('page_categories'), 'category', $categoryId);
    }

    /**
     * Returns one paginated category-page result with total count by category id.
     *
     * @param int $categoryId Category id to scope results to.
     * @param int $limit      Maximum rows to return.
     * @param int $offset     Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByCategoryId(int $categoryId, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled || $categoryId < 1) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->listTaxonomyPagedById(
            $this->table('page_categories'),
            'category',
            $categoryId,
            $limit,
            $offset,
            fn (): int => $this->countByCategoryId($categoryId)
        );
    }

    /**
     * Returns paginated pages for one tag id ordered newest-first.
     *
     * @param int $tagId  Tag id to scope results to.
     * @param int $limit  Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listByTagId(int $tagId, int $limit, int $offset): array
    {
        if (!$this->tagEnabled || $tagId < 1) {
            return [];
        }

        return $this->listTaxonomyPagesById($this->table('page_tags'), 'tag', $tagId, $limit, $offset);
    }

    /**
     * Counts total pages linked to a tag id.
     *
     * @param int $tagId Tag id to count pages for.
     * @return int Published page count for this tag.
     */
    public function countByTagId(int $tagId): int
    {
        if (!$this->tagEnabled || $tagId < 1) {
            return 0;
        }

        return $this->countTaxonomyPagesById($this->table('page_tags'), 'tag', $tagId);
    }

    /**
     * Returns one paginated tag-page result with total count by tag id.
     *
     * @param int $tagId  Tag id to scope results to.
     * @param int $limit  Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByTagId(int $tagId, int $limit, int $offset): array
    {
        if (!$this->tagEnabled || $tagId < 1) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->listTaxonomyPagedById(
            $this->table('page_tags'),
            'tag',
            $tagId,
            $limit,
            $offset,
            fn (): int => $this->countByTagId($tagId)
        );
    }

    /**
     * Hydrates page row with decoded content-block metadata.
     *
     * @param array<string, mixed> $row Raw PDO row from the pages table.
     * @return array<string, mixed> Hydrated row with `content_blocks` and `gallery_enabled` fields.
     */
    private function hydratePageRow(array $row): array
    {
        if (!array_key_exists('gallery_enabled', $row)) {
            $row['gallery_enabled'] = 0;
        }

        $rawContent = (string) ($row['content'] ?? '');
        $row['content_blocks'] = $this->decodeContentBlocks($rawContent);

        return $row;
    }

    /**
     * Decodes stored content JSON payload into typed body blocks.
     *
     * @param string $raw JSON-encoded content blocks string from the database.
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}> Decoded content blocks.
     */
    private function decodeContentBlocks(string $raw): array
    {
        return $this->pageBlockParser->decodeStoredBlocks($raw);
    }

    /**
     * Builds a map of channel id to channel row for context hydration.
     *
     * @return array<int, array<string, mixed>> Channel rows keyed by channel id.
     */
    private function channelsByIdMap(): array
    {
        return ChannelRepoParser::channelsByIdMap($this->channelRepo->listRecords());
    }

    /**
     * Hydrates one page row with channel metadata resolved from file-backed channels.
     *
     * Resolves the channel row from the channels-by-id map when not provided directly,
     * then delegates context injection to ChannelRepoParser.
     *
     * @param array<string, mixed>      $row          Hydrated page row.
     * @param array<string, mixed>|null $channel      Pre-resolved channel row, or null to auto-resolve.
     * @param array<int, array<string, mixed>>|null $channelsById Optional pre-fetched channel map.
     * @return array<string, mixed> Page row with channel context fields added.
     */
    private function withChannelContext(array $row, ?array $channel = null, ?array $channelsById = null): array
    {
        $resolvedChannel = $channel;
        if ($resolvedChannel === null) {
            $channelId = (int) ($row['channel'] ?? 0);
            if ($channelId > 0) {
                $channelsById ??= $this->channelsByIdMap();
                $resolvedChannel = $channelsById[$channelId] ?? null;
            }
        }

        return ChannelRepoParser::applyPageChannelContext($row, $resolvedChannel);
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical table name.
     * @return string Physical table name for the active driver/prefix.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Appends shared SQL clauses for page list/count queries.
     *
     * @param array<int, string>        $where                 Mutable WHERE-clause fragment list.
     * @param array<string, int|string> $params                Mutable prepared-statement parameter map.
     * @param int|null                  $channelId             Optional channel id filter.
     * @param int|null                  $categoryId            Optional category id filter.
     * @param int|null                  $tagId                 Optional tag id filter.
     * @param string                    $pageCategoriesTable   Resolved page-category junction table name.
     * @param string                    $pageTagsTable         Resolved page-tag junction table name.
     * @param string                    $placeholderPrefix     Prefix used to namespace generated placeholders.
     * @param bool                      $includeCategoryFilters Whether category filter clauses should be emitted.
     * @param bool                      $includeTagFilters      Whether tag filter clauses should be emitted.
     * @return void
     */
    private function appendFilterClauses(
        array &$where,
        array &$params,
        ?int $channelId,
        ?int $categoryId,
        ?int $tagId,
        string $pageCategoriesTable,
        string $pageTagsTable,
        string $placeholderPrefix = 'filter',
        bool $includeCategoryFilters = true,
        bool $includeTagFilters = true
    ): void {
        if ($channelId !== null && $channelId > 0) {
            $where[] = 'p.channel = :' . $placeholderPrefix . '_channel_id';
            $params[':' . $placeholderPrefix . '_channel_id'] = $channelId;
        }

        if ($includeCategoryFilters && $categoryId !== null && $categoryId > 0) {
            $where[] = 'EXISTS (
                SELECT 1 FROM ' . $pageCategoriesTable . ' pc
                WHERE pc.page = p.id AND pc.category = :' . $placeholderPrefix . '_category_id
            )';
            $params[':' . $placeholderPrefix . '_category_id'] = $categoryId;
        }

        if ($includeTagFilters && $tagId !== null && $tagId > 0) {
            $where[] = 'EXISTS (
                SELECT 1 FROM ' . $pageTagsTable . ' pt
                WHERE pt.page = p.id AND pt.tag = :' . $placeholderPrefix . '_tag_id
            )';
            $params[':' . $placeholderPrefix . '_tag_id'] = $tagId;
        }
    }

    /**
     * Counts published pages linked to one taxonomy term by slug.
     *
     * @param string $taxonomyTable Prefixed taxonomy table name (categories or tags).
     * @param string $linkTable     Prefixed page-taxonomy link table name.
     * @param string $joinCol       Link-table join column name ('category' or 'tag').
     * @param string $slug          Normalized taxonomy slug.
     * @return int Published page count for the term.
     */
    private function countTaxonomyPagesBySlug(string $taxonomyTable, string $linkTable, string $joinCol, string $slug): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $this->table('pages') . ' p
             INNER JOIN ' . $linkTable . ' pt ON pt.page = p.id
             INNER JOIN ' . $taxonomyTable . ' t ON t.id = pt.' . $joinCol . '
             WHERE t.slug = :slug AND p.status = :status'
        );
        $stmt->execute([':slug' => $slug, ':status' => 'published']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Counts published pages linked to one taxonomy term by id.
     *
     * @param string $linkTable Prefixed page-taxonomy link table name.
     * @param string $joinCol   Link-table join column name ('category' or 'tag').
     * @param int    $id        Taxonomy id to query.
     * @return int Published page count for the term.
     */
    private function countTaxonomyPagesById(string $linkTable, string $joinCol, int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $this->table('pages') . ' p
             INNER JOIN ' . $linkTable . ' pt ON pt.page = p.id
             WHERE pt.' . $joinCol . ' = :id AND p.status = :status'
        );
        $stmt->execute([':id' => $id, ':status' => 'published']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lists published pages linked to one taxonomy term by slug.
     *
     * @param string $taxonomyTable Prefixed taxonomy table name.
     * @param string $linkTable     Prefixed page-taxonomy link table name.
     * @param string $joinCol       Link-table join column name.
     * @param string $slug          Normalized taxonomy slug.
     * @param int    $limit         Maximum rows to return.
     * @param int    $offset        Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    private function listTaxonomyPagesBySlug(string $taxonomyTable, string $linkTable, string $joinCol, string $slug, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*
             FROM ' . $this->table('pages') . ' p
             INNER JOIN ' . $linkTable . ' pt ON pt.page = p.id
             INNER JOIN ' . $taxonomyTable . ' t ON t.id = pt.' . $joinCol . '
             WHERE t.slug = :slug AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $channelsById = $this->channelsByIdMap();
        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $result[] = $this->withChannelContext($this->hydratePageRow($row), null, $channelsById);
            }
        }

        return $result;
    }

    /**
     * Lists published pages linked to one taxonomy term by id.
     *
     * @param string $linkTable Prefixed page-taxonomy link table name.
     * @param string $joinCol   Link-table join column name.
     * @param int    $id        Taxonomy id to query.
     * @param int    $limit     Maximum rows to return.
     * @param int    $offset    Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    private function listTaxonomyPagesById(string $linkTable, string $joinCol, int $id, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*
             FROM ' . $this->table('pages') . ' p
             INNER JOIN ' . $linkTable . ' pt ON pt.page = p.id
             WHERE pt.' . $joinCol . ' = :id
               AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $channelsById = $this->channelsByIdMap();
        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $result[] = $this->withChannelContext($this->hydratePageRow($row), null, $channelsById);
            }
        }

        return $result;
    }

    /**
     * Returns one paginated taxonomy listing by slug using a window-count SELECT.
     *
     * When $offset > 0 and no rows come back (past the last page), $fallbackCount is
     * invoked to return the true total without a separate round-trip on page one.
     *
     * @param string   $taxonomyTable Prefixed taxonomy table name.
     * @param string   $linkTable     Prefixed page-taxonomy link table name.
     * @param string   $joinCol       Link-table join column name.
     * @param string   $slug          Normalized taxonomy slug.
     * @param int      $limit         Maximum rows to return.
     * @param int      $offset        Zero-based row offset.
     * @param callable $fallbackCount Invoked when a paged query returns no rows past the end.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    private function listTaxonomyPagedBySlug(string $taxonomyTable, string $linkTable, string $joinCol, string $slug, int $limit, int $offset, callable $fallbackCount): array
    {
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $this->table('pages') . ' p
             INNER JOIN ' . $linkTable . ' pt ON pt.page = p.id
             INNER JOIN ' . $taxonomyTable . ' t ON t.id = pt.' . $joinCol . '
             WHERE t.slug = :slug
               AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateTaxonomyPagedRows($stmt->fetchAll() ?: [], $safeOffset > 0 ? $fallbackCount : null);
    }

    /**
     * Returns one paginated taxonomy listing by id using a window-count SELECT.
     *
     * @param string   $linkTable     Prefixed page-taxonomy link table name.
     * @param string   $joinCol       Link-table join column name.
     * @param int      $id            Taxonomy id to query.
     * @param int      $limit         Maximum rows to return.
     * @param int      $offset        Zero-based row offset.
     * @param callable $fallbackCount Invoked when a paged query returns no rows past the end.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    private function listTaxonomyPagedById(string $linkTable, string $joinCol, int $id, int $limit, int $offset, callable $fallbackCount): array
    {
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $this->table('pages') . ' p
             INNER JOIN ' . $linkTable . ' pt ON pt.page = p.id
             WHERE pt.' . $joinCol . ' = :id
               AND p.status = :status
             ORDER BY p.created DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'published', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateTaxonomyPagedRows($stmt->fetchAll() ?: [], $safeOffset > 0 ? $fallbackCount : null);
    }

    /**
     * Hydrates paginated taxonomy rows, preserving the window-count total.
     *
     * Window counts avoid a second round-trip for the common page-one case. When an
     * out-of-range offset returns no rows, $fallbackCount is invoked to recover the total.
     *
     * @param array<int, mixed>  $rows          Raw PDO rows including `total_rows` window column.
     * @param callable|null      $fallbackCount Optional fallback count invoked when an empty page is past the end.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Hydrated rows and total count.
     */
    private function hydrateTaxonomyPagedRows(array $rows, ?callable $fallbackCount): array
    {
        $channelsById = $this->channelsByIdMap();
        $total = 0;
        $resultRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->withChannelContext($this->hydratePageRow($row), null, $channelsById);
        }

        if ($resultRows === [] && $fallbackCount !== null) {
            $total = $fallbackCount();
        }

        return ['rows' => $resultRows, 'total' => $total];
    }
}
