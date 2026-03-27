<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use Raven\Lib\Content\PageBodyBlockCodec;
use Raven\Lib\Content\PagePersistenceService;
use Raven\Lib\Content\PagePanelFilterClauseBuilder;
use Raven\Lib\Content\PageTaxonomyAssignmentService;
use Raven\Lib\Content\PageTaxonomyQueryService;
use Raven\Lib\Database\Runtime\TableNameResolver;
use Raven\Lib\Media\PageEditorGalleryHydrator;
use Raven\Lib\Routing\ChannelRecordPolicy;
use Raven\Lib\Routing\ChannelContextService;
use Raven\Lib\Routing\PathScopeLookupService;
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
    private PageBodyBlockCodec $bodyBlockCodec;
    private PageEditorGalleryHydrator $pageEditorGalleryHydrator;
    private PagePanelFilterClauseBuilder $panelFilterClauseBuilder;
    private PageTaxonomyAssignmentService $pageTaxonomyAssignmentService;
    private PageTaxonomyQueryService $pageTaxonomyQueryService;
    private PagePersistenceService $pagePersistenceService;

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
        $this->bodyBlockCodec = new PageBodyBlockCodec();
        $this->pageEditorGalleryHydrator = new PageEditorGalleryHydrator();
        $this->panelFilterClauseBuilder = new PagePanelFilterClauseBuilder();
        $this->pageTaxonomyAssignmentService = new PageTaxonomyAssignmentService();
        $this->pageTaxonomyQueryService = new PageTaxonomyQueryService();
        $this->pagePersistenceService = new PagePersistenceService();
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
                WHERE (p.channel_id = 0 OR p.channel_id IS NULL)
                  AND p.is_published = :is_published
                  AND p.slug IN (:slug_home, :slug_index)
                ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                         p.created_at DESC
                LIMIT 1';

        // CASE ordering guarantees `home` wins over `index` when both exist.
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':is_published' => 1,
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
     * Channel page must be published and belong to the requested channel slug.
     *
     * @return array<string, mixed>|null
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
                WHERE p.channel_id = :channel_id
                  AND p.is_published = :is_published
                  AND p.slug IN (:slug_home, :slug_index)
                ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                         p.created_at DESC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':channel_id' => $channelId,
            ':is_published' => 1,
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->withChannelContext($this->hydratePageRow($row), $channel);
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
                  AND p.is_published = :is_published';

        $params = [
            ':page_slug' => $pageSlug,
            ':is_published' => 1,
        ];

        // Unchanneled pages resolve at root; channeled pages require explicit channel slug match.
        $channel = null;
        if ($channelSlug === null) {
            $sql .= ' AND (p.channel_id = 0 OR p.channel_id IS NULL)';
        } else {
            $channel = $this->channelRepo->findBySlug($channelSlug);
            if ($channel === null) {
                return null;
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                return null;
            }

            $sql .= ' AND p.channel_id = :channel_id';
            $params[':channel_id'] = $channelId;
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
                  AND p.is_published = :is_published';

        $params = [
            ':page_id' => $pageId,
            ':is_published' => 1,
        ];

        $channel = null;
        if ($channelSlug === null) {
            $sql .= ' AND (p.channel_id = 0 OR p.channel_id IS NULL)';
        } else {
            $channel = $this->channelRepo->findBySlug($channelSlug);
            if ($channel === null) {
                return null;
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                return null;
            }

            $sql .= ' AND p.channel_id = :channel_id';
            $params[':channel_id'] = $channelId;
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
                WHERE p.is_published = :is_published';
        $params = [
            ':is_published' => 1,
        ];

        $channel = null;
        if ($normalizedChannelSlug === ChannelRecordPolicy::ROOT_CHANNEL_SLUG) {
            $sql .= ' AND (p.channel_id = 0 OR p.channel_id IS NULL)';
        } elseif ($normalizedChannelSlug !== '') {
            $channel = $this->channelRepo->findBySlug($normalizedChannelSlug);
            if ($channel === null) {
                return [];
            }

            $channelId = (int) ($channel['id'] ?? 0);
            if ($channelId < 1) {
                return [];
            }

            $sql .= ' AND p.channel_id = :channel_id';
            $params[':channel_id'] = $channelId;
        }

        $sql .= '
                ORDER BY p.created_at DESC, p.id DESC
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
                WHERE p.is_published = :is_published';
        $params = [
            ':is_published' => 1,
        ];

        $clauses = [];
        $includeRoot = isset($normalizedSlugs[ChannelRecordPolicy::ROOT_CHANNEL_SLUG]);
        unset($normalizedSlugs[ChannelRecordPolicy::ROOT_CHANNEL_SLUG]);

        if ($includeRoot) {
            $clauses[] = '(p.channel_id = 0 OR p.channel_id IS NULL)';
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
                $placeholder = ':channel_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $channelId;
                $index++;
            }

            $clauses[] = 'p.channel_id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($clauses === []) {
            return [];
        }

        $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
        $sql .= '
                ORDER BY p.created_at DESC, p.id DESC
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
     */
    public function countForPanel(?string $channelSlug = null, ?int $categoryId = null, ?int $tagId = null): int
    {
        $pages = $this->table('pages');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');

        $where = ['1 = 1'];
        $params = [];
        $this->appendPanelFilterClauses(
            $where,
            $params,
            $channelSlug,
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
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(
        int $limit = 50,
        int $offset = 0,
        ?string $channelSlug = null,
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
            $channelSlug,
            $categoryId,
            $tagId,
            $pageCategories,
            $pageTags,
            'filter',
            $this->categoryEnabled,
            $this->tagEnabled
        );

        $stmt = $this->db->prepare(
            'SELECT p.id, p.title, p.slug, p.is_published, p.created_at, p.channel_id
             FROM ' . $pages . ' p
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.created_at DESC
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
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(
        int $limit = 50,
        int $offset = 0,
        ?string $channelSlug = null,
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
            $channelSlug,
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
            $channelSlug,
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
                    page_rows.is_published,
                    page_rows.created_at,
                    page_rows.channel_id,
                    totals.total_rows
             FROM (
                 SELECT p.id, p.title, p.slug, p.is_published, p.created_at, p.channel_id
                 FROM ' . $pages . ' p
                 WHERE ' . implode(' AND ', $pageWhere) . '
                 ORDER BY p.created_at DESC
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
            $total = $this->countForPanel($channelSlug, $categoryId, $tagId);
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
            'SELECT p.id, p.title, p.slug, p.is_published, p.created_at, p.channel_id
             FROM ' . $pages . ' p
             ORDER BY COALESCE(p.channel_id, 0) ASC, p.slug ASC, p.id ASC'
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
            'SELECT p.channel_id, p.slug
             FROM ' . $pages . ' p
             WHERE p.channel_id IS NOT NULL
               AND p.channel_id <> 0
               AND p.is_published = :is_published
               AND p.slug IN (:slug_home, :slug_index)
             ORDER BY p.channel_id ASC,
                      CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                      p.created_at DESC'
        );
        $stmt->execute([
            ':is_published' => 1,
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $seen = [];
        foreach ($rows as $row) {
            $channelId = (int) ($row['channel_id'] ?? 0);
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
                i.page_id AS image_page_id,
                i.storage_target AS image_storage_target,
                i.original_filename AS image_original_filename,
                i.stored_filename AS image_stored_filename,
                i.stored_path AS image_stored_path,
                i.mime_type AS image_mime_type,
                i.extension AS image_extension,
                i.byte_size AS image_byte_size,
                i.width AS image_width,
                i.height AS image_height,
                i.hash_sha256 AS image_hash_sha256,
                i.status AS image_status,
                i.sort_order AS image_sort_order,
                i.is_cover AS image_is_cover,
                i.include_in_gallery AS image_include_in_gallery,
                i.alt_text AS image_alt_text,
                i.title_text AS image_title_text,
                i.caption AS image_caption,
                i.credit AS image_credit,
                i.license AS image_license,
                i.focal_x AS image_focal_x,
                i.focal_y AS image_focal_y,
                i.created_at AS image_created_at,
                i.updated_at AS image_updated_at,
                v.variant_key AS variant_key,
                v.stored_filename AS variant_stored_filename,
                v.stored_path AS variant_stored_path,
                v.mime_type AS variant_mime_type,
                v.extension AS variant_extension,
                v.byte_size AS variant_byte_size,
                v.width AS variant_width,
                v.height AS variant_height
             FROM ' . $pages . ' p
             LEFT JOIN ' . $images . ' i ON i.page_id = p.id
             LEFT JOIN ' . $variants . ' v ON v.image_id = i.id
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
        $pages = $this->table('pages');

        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $title = (string) ($data['title'] ?? 'Untitled');
        $slug = (string) ($data['slug'] ?? '');
        $contentBlocks = $this->normalizeContentBlocks($data['content_blocks'] ?? []);
        $content = $this->encodeContentBlocks($contentBlocks);
        $description = (string) ($data['description'] ?? '');
        $displayTitle = !array_key_exists('display_title', $data) || !empty($data['display_title']) ? 1 : 0;
        $galleryEnabled = !empty($data['gallery_enabled']) ? 1 : 0;
        $isPublished = !empty($data['is_published']) ? 1 : 0;
        $authorUserId = isset($data['author_user_id']) ? (int) $data['author_user_id'] : 0;
        if ($authorUserId < 1) {
            $authorUserId = null;
        }
        $now = gmdate('Y-m-d H:i:s');
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

        return $this->pagePersistenceService->savePage(
            $this->db,
            $pages,
            [
                'id' => $id,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'description' => $description,
                'display_title' => $displayTitle,
                'gallery_enabled' => $galleryEnabled,
                'is_published' => $isPublished,
                'author_user_id' => $authorUserId,
                'channel_id' => $channelId,
                'now' => $now,
                'category_ids' => $categoryIds,
                'tag_ids' => $tagIds,
            ],
            $this->categoryEnabled,
            $this->tagEnabled,
            function (int $pageId, array $ids): void {
                $this->replacePageCategories($pageId, $ids);
            },
            function (int $pageId, array $ids): void {
                $this->replacePageTags($pageId, $ids);
            }
        );
    }

    /**
     * Returns true when another page already uses the same path scope.
     */
    private function pathExists(string $slug, ?int $channelId, ?int $excludeId = null): bool
    {
        return PathScopeLookupService::exists(
            $this->db,
            $this->table('pages'),
            $slug,
            $channelId,
            $excludeId,
            'exclude_id'
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
             INNER JOIN ' . $categories . ' c ON c.id = pc.category_id
             WHERE pc.page_id = :page_id
             ORDER BY c.name ASC, c.id ASC'
        );
        $stmt->execute([':page_id' => $pageId]);

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
             INNER JOIN ' . $tags . ' t ON t.id = pt.tag_id
             WHERE pt.page_id = :page_id
             ORDER BY t.name ASC, t.id ASC'
        );
        $stmt->execute([':page_id' => $pageId]);

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
                'SELECT page_id, category_id AS taxonomy_id, \'category\' AS taxonomy_type
                 FROM ' . $pageCategories . '
                 WHERE page_id IN (' . $categoryPlaceholders . ')';
            $params = array_merge($params, $normalizedPageIds);
        }
        if ($this->tagEnabled) {
            $tagPlaceholders = implode(', ', array_fill(0, count($normalizedPageIds), '?'));
            $pageTags = $this->table('page_tags');
            $unionQueries[] =
                'SELECT page_id, tag_id AS taxonomy_id, \'tag\' AS taxonomy_type
                 FROM ' . $pageTags . '
                 WHERE page_id IN (' . $tagPlaceholders . ')';
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
        $this->pagePersistenceService->deletePageById(
            $this->db,
            $this->table('pages'),
            $this->table('page_categories'),
            $this->table('page_tags'),
            $this->table('page_images'),
            $this->table('page_image_variants'),
            $id,
            $this->categoryEnabled,
            $this->tagEnabled
        );
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

        return $this->pageTaxonomyQueryService->listByCategorySlug(
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

        return $this->pageTaxonomyQueryService->countByCategorySlug(
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

        return $this->pageTaxonomyQueryService->listByTagSlug(
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

        return $this->pageTaxonomyQueryService->countByTagSlug(
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

        return $this->pageTaxonomyQueryService->listPageByCategorySlug(
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

        return $this->pageTaxonomyQueryService->listPageByTagSlug(
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
     * Hydrates page row with repeatable content-block metadata.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePageRow(array $row): array
    {
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
        return $this->bodyBlockCodec->normalizeStoredBlocks($raw);
    }

    /**
     * Encodes content blocks as JSON for DB persistence.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
     */
    private function encodeContentBlocks(array $blocks): string
    {
        return $this->bodyBlockCodec->encodeStoredBlocks($blocks);
    }

    /**
     * Decodes stored content JSON payload into typed body blocks.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function decodeContentBlocks(string $raw): array
    {
        return $this->bodyBlockCodec->decodeStoredBlocks($raw);
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
        return ChannelContextService::channelsByIdMap($this->channelRepo->listRecords());
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
            $channelId = (int) ($row['channel_id'] ?? 0);
            if ($channelId > 0) {
                $channelsById ??= $this->channelsByIdMap();
                $resolvedChannel = $channelsById[$channelId] ?? null;
            }
        }

        return ChannelContextService::applyPageChannelContext($row, $resolvedChannel);
    }

    /**
     * Resolves channel id by slug for page save operations.
     */
    private function channelIdBySlug(string $slug): ?int
    {
        if (ChannelRecordPolicy::isRootChannelSlug($slug)) {
            throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
        }

        return ChannelContextService::resolveChannelIdBySlug(
            $slug,
            fn (string $normalized): ?int => $this->channelRepo->idBySlug($normalized),
            'Selected channel does not exist.'
        );
    }

    /**
     * Replaces all category assignments for one page id.
     *
     * @param array<int> $categoryIds
     */
    private function replacePageCategories(int $pageId, array $categoryIds): void
    {
        $this->pageTaxonomyAssignmentService->replacePageCategories(
            $this->db,
            $this->driver,
            $this->table('page_categories'),
            $pageId,
            $categoryIds
        );
    }

    /**
     * Replaces all tag assignments for one page id.
     *
     * @param array<int> $tagIds
     */
    private function replacePageTags(int $pageId, array $tagIds): void
    {
        $this->pageTaxonomyAssignmentService->replacePageTags(
            $this->db,
            $this->driver,
            $this->table('page_tags'),
            $pageId,
            $tagIds
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
     * @param array<int, string> $where
     * @param array<string, int|string> $params
     */
    private function appendPanelFilterClauses(
        array &$where,
        array &$params,
        ?string $channelSlug,
        ?int $categoryId,
        ?int $tagId,
        string $pageCategoriesTable,
        string $pageTagsTable,
        string $placeholderPrefix = 'filter',
        bool $includeCategoryFilters = true,
        bool $includeTagFilters = true
    ): void {
        $this->panelFilterClauseBuilder->append(
            $where,
            $params,
            $channelSlug,
            $categoryId,
            $tagId,
            $pageCategoriesTable,
            $pageTagsTable,
            fn (string $slug): ?int => $this->channelRepo->idBySlug($slug),
            $placeholderPrefix,
            $includeCategoryFilters,
            $includeTagFilters
        );
    }
}
