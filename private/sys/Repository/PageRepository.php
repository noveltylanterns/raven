<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Parser\ChannelContextParser;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Parser\TaxonomyDataParser;
use Raven\Lib\Scribe\PageScribe;
use Raven\Lib\View\Panel\ListFilter;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Media\Panel\PageEditorGalleryHydrator;
use Raven\Lib\Parser\PageDuplicateParser;
use RuntimeException;

/**
 * Data access for pages and their public listing queries.
 */
final class PageRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRepository $channelRepo;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PageBlockParser $pageBlockParser;
    private PageEditorGalleryHydrator $pageEditorGalleryHydrator;
    private ListFilter $panelListFilter;
    private TaxonomyDataParser $taxonomyDataParser;
    private PageScribe $pageScribe;

    public function __construct(
        PDO $db,
        string $driver,
        string $prefix,
        ChannelRepository $channelRepo,
        bool $categoryEnabled = true,
        bool $tagEnabled = true
    )
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->channelRepo = $channelRepo;
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->pageBlockParser = new PageBlockParser();
        $this->pageEditorGalleryHydrator = new PageEditorGalleryHydrator();
        $this->panelListFilter = new ListFilter();
        $this->taxonomyDataParser = new TaxonomyDataParser();
        $this->pageScribe = new PageScribe($db, $driver, $prefix, $categoryEnabled, $tagEnabled);
    }

    /**
     * Finds homepage by slug priority: `home` first, then `index`.
     *
     * Page must not belong to any channel.
     *
     * @return array<string, mixed>|null
     */
    public function findHomepage(): ?array
    {
        $pages = $this->table('pages');

        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.channel = 0
                  AND p.status = :status
                  AND p.slug IN (:slug_home, :slug_index)
                ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                         p.created DESC
                LIMIT 1';

        // CASE ordering guarantees `home` wins over `index` when both exist.
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => 'published',
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydratePageRow($row);
    }

    /**
     * Finds channel homepage by slug priority: `home` first, then `index`.
     *
     * Returns the resolved channel and its homepage as a named-key tuple so the
     * caller can reuse the already-fetched channel row without a second DB round-trip.
     * Returns null when the channel slug does not resolve to a known channel.
     * When the channel exists but has no published homepage, `page` is null.
     *
     * Channel page must be published and belong to the requested channel slug.
     *
     * @param string $channelSlug Normalized channel slug to look up.
     * @return array{channel: array<string,mixed>, page: ?array<string,mixed>}|null
     *         Null when the channel itself is not found; otherwise an array with
     *         'channel' (the resolved channel row) and 'page' (the homepage row or null).
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

        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.channel = :channel
                  AND p.status = :status
                  AND p.slug IN (:slug_home, :slug_index)
                ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                         p.created DESC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
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
     * Finds one public page by slug and optional channel slug.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicPage(string $pageSlug, ?string $channelSlug = null): ?array
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

        // Unchanneled pages resolve at root; channeled pages require explicit channel slug match.
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
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($this->hydratePageRow($row), $channel);
    }

    /**
     * Finds one public page by id and optional channel slug.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicPageById(int $pageId, ?string $channelSlug = null): ?array
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
        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($this->hydratePageRow($row), $channel);
    }

    /**
     * Returns one page by slug and optional channel scope.
     *
     * $channel accepts a channel ID (int), a channel slug (string), or null for root scope.
     * Root scope matches pages that do not belong to any channel.
     *
     * @return array<string, mixed>|null
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
     * $channel accepts a channel ID (int), a channel slug (string), or null for root scope.
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
     * @return array<int, array<string, mixed>>
     */
    public function listRecentPublished(int $limit, ?string $channelSlug = null): array
    {
        $safeLimit = max(1, $limit);
        $normalizedChannelSlug = strtolower(trim((string) ($channelSlug ?? '')));
        $pages = $this->table('pages');
        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                WHERE p.status = :status';
        $params = [
            ':status' => 'published',
        ];

        $channel = null;
        if ($normalizedChannelSlug === ChannelContextParser::ROOT_CHANNEL_SLUG) {
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
     * Returns newest published pages scoped to an explicit list of channels.
     *
     * @param array<int, string> $channelSlugs
     * @return array<int, array<string, mixed>>
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
        $params = [
            ':status' => 'published',
        ];

        $clauses = [];
        $includeRoot = isset($normalizedSlugs[ChannelContextParser::ROOT_CHANNEL_SLUG]);
        unset($normalizedSlugs[ChannelContextParser::ROOT_CHANNEL_SLUG]);

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
     * Returns one total-count for panel page index with optional prefilters.
     *
     * @param int|null $channelId Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter from the panel UI.
     * @param int|null $tagId Optional tag id filter from the panel UI.
     * @return int Total matching page count.
     */
    public function countForPanel(?int $channelId = null, ?int $categoryId = null, ?int $tagId = null): int
    {
        $pages = $this->table('pages');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');

        $where = ['1 = 1'];
        $params = [];
        $this->appendPanelFilterClauses(
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
     * Returns paginated page list for panel page index with optional prefilters.
     *
     * @param int $limit Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @param int|null $channelId Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter from the panel UI.
     * @param int|null $tagId Optional tag id filter from the panel UI.
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(
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
        $this->appendPanelFilterClauses(
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
     * Returns one paginated panel page-list page plus total row count.
     *
     * @param int $limit Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @param int|null $channelId Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter from the panel UI.
     * @param int|null $tagId Optional tag id filter from the panel UI.
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(
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
        $this->appendPanelFilterClauses(
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
        $this->appendPanelFilterClauses(
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
            $total = $this->countForPanel($channelId, $categoryId, $tagId);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns all pages with channel context for routing inventory screens.
     *
     * @return array<int, array<string, mixed>>
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
     * Landing priority per channel:
     * - `home` first
     * - fallback `index`
     *
     * @return array<string, string>
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
     * Returns one page by id for panel edit form.
     *
     * @return array<string, mixed>|null
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

        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($this->hydratePageRow($row));
    }

    /**
     * Returns page-edit payload with page row and gallery rows in one query.
     *
     * @return array{page: array<string, mixed>, gallery_images: array<int, array<string, mixed>>}|null
     */
    public function editFormDataById(int $id): ?array
    {
        $pages = $this->table('pages');
        $images = $this->table('page_images');
        $variants = $this->table('page_image_variants');

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

        return [
            'page' => $this->withChannelContext($this->hydratePageRow($this->stripEditorMediaColumns($rows[0]))),
            'gallery_images' => $this->hydrateEditorGalleryRows($rows),
        ];
    }

    /**
     * Creates or updates page row from panel form payload.
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data): int
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $title = (string) ($data['title'] ?? 'Untitled');
        $slug = (string) ($data['slug'] ?? '');
        $contentBlocks = $this->normalizeContentBlocks($data['content_blocks'] ?? []);
        $content = $this->encodeContentBlocks($contentBlocks);
        $description = (string) ($data['description'] ?? '');
        $displayTitle = !array_key_exists('display_title', $data) || !empty($data['display_title']) ? 1 : 0;
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if (!in_array($status, ['published', 'draft'], true)) {
            $status = 'draft';
        }
        $author = isset($data['author']) ? (int) $data['author'] : 0;
        if ($author < 1) {
            $author = null;
        }
        $now = gmdate('Y-m-d H:i:s');
        $publishAt = $this->normalizeDateTimeField($data['published'] ?? null);
        $expireAt = $this->normalizeDateTimeField($data['expires'] ?? null);
        $categoryIds = $this->categoryEnabled ? $this->normalizeIds($data['category_ids'] ?? []) : [];
        $tagIds = $this->tagEnabled ? $this->normalizeIds($data['tag_ids'] ?? []) : [];

        // Optional channel binding by slug; channel id `0` is the stock root scope.
        $channelId = 0;
        if (!empty($data['channel_slug'])) {
            $channelId = $this->channelIdBySlug((string) $data['channel_slug']);
            if ($channelId !== null && $channelId < 1) {
                throw new \RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
            }
        }

        if ($slug === '') {
            throw new \RuntimeException('Page slug is required.');
        }

        // Path uniqueness is scoped to (channel, slug) pairs.
        if ($this->pathExists($slug, $channelId, $id > 0 ? $id : null)) {
            throw new \RuntimeException('A page already exists for that slug/channel path.');
        }

        return $this->pageScribe->save([
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'description' => $description,
            'display_title' => $displayTitle,
            'status' => $status,
            'published' => $publishAt,
            'expires' => $expireAt,
            'author' => $author,
            'channel' => $channelId,
            'now' => $now,
            'category_ids' => $categoryIds,
            'tag_ids' => $tagIds,
        ]);
    }

    /**
     * Flips page statuses based on published / expires schedule columns.
     *
     * - draft pages whose published is in the past become published.
     * - published pages whose expires is in the past become draft.
     *
     * Safe to call on every public request; only updates rows that need flipping.
     */
    public function applySchedule(): void
    {
        $pages = $this->table('pages');
        $now = gmdate('Y-m-d H:i:s');

        $this->db->prepare(
            'UPDATE ' . $pages . '
             SET status = \'published\', updated = :now
             WHERE status = \'draft\'
               AND published IS NOT NULL
               AND published <= :now2'
        )->execute([':now' => $now, ':now2' => $now]);

        $this->db->prepare(
            'UPDATE ' . $pages . '
             SET status = \'draft\', updated = :now
             WHERE status = \'published\'
               AND expires IS NOT NULL
               AND expires <= :now2'
        )->execute([':now' => $now, ':now2' => $now]);
    }

    /**
     * Normalises a datetime string from panel input to Y-m-d H:i:s DB format, or null.
     *
     * Accepts `Y-m-d H:i:s`, `Y-m-d H:i`, and HTML datetime-local format `Y-m-d\TH:i`.
     */
    private function normalizeDateTimeField(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        // datetime-local browser format: 2026-03-27T14:30 or 2026-03-27T14:30:00
        $value = str_replace('T', ' ', $value);

        // Append seconds if missing (H:i → H:i:s)
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }

        // Validate basic Y-m-d H:i:s shape
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Returns true when another page already uses the same path scope.
     */
    private function pathExists(string $slug, ?int $channelId, ?int $excludeId = null): bool
    {
        return PageDuplicateParser::exists(
            $this->db,
            $this->table('pages'),
            $slug,
            $channelId,
            $excludeId,
            'exclude_id',
            'channel'
        );
    }

    /**
     * Returns assigned categories for one page.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
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
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $result;
    }

    /**
     * Returns assigned tags for one page.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
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
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $result;
    }

    /**
     * Returns category/tag assignment ids grouped by page id.
     *
     * @param array<int> $pageIds
     * @return array<int, array{categories: array<int>, tags: array<int>}>
     */
    public function taxonomyAssignmentIdsByPage(array $pageIds): array
    {
        $normalizedPageIds = $this->normalizeIds($pageIds);
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
     * Deletes one page and clears its category/tag links first.
     *
     * @param int $id
     */
    public function deleteById(int $id): void
    {
        $this->pageScribe->deleteById($id);
    }

    /**
     * Returns paginated pages for one category slug ordered newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByCategorySlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled) {
            return [];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPagesByCategorySlug(
            $this->db,
            $this->table('pages'),
            $this->table('categories'),
            $this->table('page_categories'),
            $slug,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById)
        );
    }

    /**
     * Counts total pages linked to a category slug.
     */
    public function countByCategorySlug(string $slug): int
    {
        if (!$this->categoryEnabled) {
            return 0;
        }

        return $this->taxonomyDataParser->countPagesByCategorySlug(
            $this->db,
            $this->table('pages'),
            $this->table('categories'),
            $this->table('page_categories'),
            $slug
        );
    }

    /**
     * Returns paginated pages for one tag slug ordered newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByTagSlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->tagEnabled) {
            return [];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPagesByTagSlug(
            $this->db,
            $this->table('pages'),
            $this->table('tags'),
            $this->table('page_tags'),
            $slug,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById)
        );
    }

    /**
     * Counts total pages linked to a tag slug.
     */
    public function countByTagSlug(string $slug): int
    {
        if (!$this->tagEnabled) {
            return 0;
        }

        return $this->taxonomyDataParser->countPagesByTagSlug(
            $this->db,
            $this->table('pages'),
            $this->table('tags'),
            $this->table('page_tags'),
            $slug
        );
    }

    /**
     * Returns one paginated category-page result with total count.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByCategorySlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled) {
            return ['rows' => [], 'total' => 0];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPageByCategorySlug(
            $this->db,
            $this->table('pages'),
            $this->table('categories'),
            $this->table('page_categories'),
            $slug,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById),
            fn (string $taxonomySlug): int => $this->countByCategorySlug($taxonomySlug)
        );
    }

    /**
     * Returns one paginated tag-page result with total count.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByTagSlug(string $slug, int $limit, int $offset): array
    {
        if (!$this->tagEnabled) {
            return ['rows' => [], 'total' => 0];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPageByTagSlug(
            $this->db,
            $this->table('pages'),
            $this->table('tags'),
            $this->table('page_tags'),
            $slug,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById),
            fn (string $taxonomySlug): int => $this->countByTagSlug($taxonomySlug)
        );
    }

    /**
     * Returns paginated pages for one category id ordered newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByCategoryId(int $categoryId, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled || $categoryId < 1) {
            return [];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPagesByCategoryId(
            $this->db,
            $this->table('pages'),
            $this->table('page_categories'),
            $categoryId,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById)
        );
    }

    /**
     * Counts total pages linked to a category id.
     */
    public function countByCategoryId(int $categoryId): int
    {
        if (!$this->categoryEnabled || $categoryId < 1) {
            return 0;
        }

        return $this->taxonomyDataParser->countPagesByCategoryId(
            $this->db,
            $this->table('pages'),
            $this->table('page_categories'),
            $categoryId
        );
    }

    /**
     * Returns one paginated category-page result with total count by category id.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByCategoryId(int $categoryId, int $limit, int $offset): array
    {
        if (!$this->categoryEnabled || $categoryId < 1) {
            return ['rows' => [], 'total' => 0];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPageByCategoryId(
            $this->db,
            $this->table('pages'),
            $this->table('page_categories'),
            $categoryId,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById),
            fn (int $taxonomyId): int => $this->countByCategoryId($taxonomyId)
        );
    }

    /**
     * Returns paginated pages for one tag id ordered newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByTagId(int $tagId, int $limit, int $offset): array
    {
        if (!$this->tagEnabled || $tagId < 1) {
            return [];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPagesByTagId(
            $this->db,
            $this->table('pages'),
            $this->table('page_tags'),
            $tagId,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById)
        );
    }

    /**
     * Counts total pages linked to a tag id.
     */
    public function countByTagId(int $tagId): int
    {
        if (!$this->tagEnabled || $tagId < 1) {
            return 0;
        }

        return $this->taxonomyDataParser->countPagesByTagId(
            $this->db,
            $this->table('pages'),
            $this->table('page_tags'),
            $tagId
        );
    }

    /**
     * Returns one paginated tag-page result with total count by tag id.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByTagId(int $tagId, int $limit, int $offset): array
    {
        if (!$this->tagEnabled || $tagId < 1) {
            return ['rows' => [], 'total' => 0];
        }

        $channelsById = $this->channelsByIdMap();

        return $this->taxonomyDataParser->listPageByTagId(
            $this->db,
            $this->table('pages'),
            $this->table('page_tags'),
            $tagId,
            $limit,
            $offset,
            fn (array $row): array => $this->withChannelContext($this->hydratePageRow($row), null, $channelsById),
            fn (int $taxonomyId): int => $this->countByTagId($taxonomyId)
        );
    }

    /**
     * Hydrates page row with repeatable content-block metadata.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePageRow(array $row): array
    {
        if (!array_key_exists('gallery_enabled', $row)) {
            $row['gallery_enabled'] = 0;
        }

        $rawContent = (string) ($row['content'] ?? '');
        $contentBlocks = $this->decodeContentBlocks($rawContent);
        $row['content_blocks'] = $contentBlocks;

        return $row;
    }

    /**
     * Normalizes body-block payload into typed, persistable rows.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function normalizeContentBlocks(mixed $raw): array
    {
        return $this->pageBlockParser->normalizeStoredBlocks($raw);
    }

    /**
     * Encodes content blocks as JSON for DB persistence.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
     */
    private function encodeContentBlocks(array $blocks): string
    {
        return $this->pageBlockParser->encodeStoredBlocks($blocks);
    }

    /**
     * Decodes stored content JSON payload into typed body blocks.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function decodeContentBlocks(string $raw): array
    {
        return $this->pageBlockParser->decodeStoredBlocks($raw);
    }

    /**
     * Drops media-join columns from combined page-editor row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stripEditorMediaColumns(array $row): array
    {
        return $this->pageEditorGalleryHydrator->stripEditorMediaColumns($row);
    }

    /**
     * Hydrates page-editor image/variant rows from combined query output.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateEditorGalleryRows(array $rows): array
    {
        return $this->pageEditorGalleryHydrator->hydrate(
            $rows,
            fn (string $storedPath): string => $this->publicUrlFromStoredPath($storedPath)
        );
    }

    /**
     * Converts one stored relative path into a public URL path.
     */
    private function publicUrlFromStoredPath(string $storedPath): string
    {
        return '/' . ltrim($storedPath, '/');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function channelsByIdMap(): array
    {
        return ChannelContextParser::channelsByIdMap($this->channelRepo->listRecords());
    }

    /**
     * Hydrates one page row with channel metadata resolved from file-backed channels.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $channel
     * @param array<int, array<string, mixed>>|null $channelsById
     * @return array<string, mixed>
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

        return ChannelContextParser::applyPageChannelContext($row, $resolvedChannel);
    }

    /**
     * Resolves channel id by slug for page save operations.
     */
    private function channelIdBySlug(string $slug): ?int
    {
        if (ChannelContextParser::isRootChannelSlug($slug)) {
            throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
        }

        return ChannelContextParser::resolveChannelIdBySlug(
            $slug,
            fn (string $normalized): ?int => $this->channelRepo->idBySlug($normalized),
            'Selected channel does not exist.'
        );
    }

    /**
     * Normalizes ids into unique positive integers.
     *
     * @param mixed $ids
     * @return array<int>
     */
    private function normalizeIds(mixed $ids): array
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
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Appends shared panel-filter SQL clauses for page list/count queries.
     *
     * @param array<int, string> $where Mutable WHERE-clause fragment list.
     * @param array<string, int|string> $params Mutable prepared-statement parameter map.
     * @param int|null $channelId Optional channel id filter resolved before repository entry.
     * @param int|null $categoryId Optional category id filter from the panel UI.
     * @param int|null $tagId Optional tag id filter from the panel UI.
     * @param string $pageCategoriesTable Resolved page-category junction table name.
     * @param string $pageTagsTable Resolved page-tag junction table name.
     * @param string $placeholderPrefix Prefix used to namespace generated placeholders.
     * @param bool $includeCategoryFilters Whether category filter clauses should be emitted.
     * @param bool $includeTagFilters Whether tag filter clauses should be emitted.
     * @return void
     */
    private function appendPanelFilterClauses(
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
        $this->panelListFilter->appendIntEquals(
            $where,
            $params,
            'p.channel',
            $channelId,
            $placeholderPrefix,
            'channel'
        );

        if ($includeCategoryFilters) {
            $this->panelListFilter->appendExistsIntMatch(
                $where,
                $params,
                $pageCategoriesTable,
                'pc',
                'pc.page',
                'p.id',
                'pc.category',
                $categoryId,
                $placeholderPrefix,
                'category'
            );
        }

        if ($includeTagFilters) {
            $this->panelListFilter->appendExistsIntMatch(
                $where,
                $params,
                $pageTagsTable,
                'pt',
                'pt.page',
                'p.id',
                'pt.tag',
                $tagId,
                $placeholderPrefix,
                'tag'
            );
        }
    }
}
