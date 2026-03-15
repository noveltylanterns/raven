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
use Raven\Lib\Content\PagePanelFilterClauseBuilder;
use Raven\Lib\Content\PageTaxonomyAssignmentService;
use Raven\Lib\Database\Runtime\TableNameResolver;
use Raven\Lib\Media\PageEditorGalleryHydrator;
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
    private ChannelRepository $channels;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PageBodyBlockCodec $bodyBlockCodec;
    private PageEditorGalleryHydrator $pageEditorGalleryHydrator;
    private PagePanelFilterClauseBuilder $panelFilterClauseBuilder;
    private PageTaxonomyAssignmentService $pageTaxonomyAssignmentService;

    public function __construct(
        PDO $db,
        string $driver,
        string $prefix,
        ChannelRepository $channels,
        bool $categoryEnabled = true,
        bool $tagEnabled = true
    )
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is only used in shared-db modes; SQLite uses attached DB names instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
        $this->channels = $channels;
        $this->categoryEnabled = $categoryEnabled;
        $this->tagEnabled = $tagEnabled;
        $this->bodyBlockCodec = new PageBodyBlockCodec();
        $this->pageEditorGalleryHydrator = new PageEditorGalleryHydrator();
        $this->panelFilterClauseBuilder = new PagePanelFilterClauseBuilder();
        $this->pageTaxonomyAssignmentService = new PageTaxonomyAssignmentService();
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
                WHERE p.channel_id IS NULL
                  AND p.is_published = :is_published
                  AND p.slug IN (:slug_home, :slug_index)
                ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                         p.published_at DESC
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
        $channel = $this->channels->findBySlug($channelSlug);
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
                         p.published_at DESC
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
            $sql .= ' AND p.channel_id IS NULL';
        } else {
            $channel = $this->channels->findBySlug($channelSlug);
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
            'SELECT p.id, p.title, p.slug, p.is_published, p.published_at, p.channel_id
             FROM ' . $pages . ' p
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(p.published_at, p.created_at) DESC
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
                    page_rows.published_at,
                    page_rows.channel_id,
                    totals.total_rows
             FROM (
                 SELECT p.id, p.title, p.slug, p.is_published, p.published_at, p.channel_id
                 FROM ' . $pages . ' p
                 WHERE ' . implode(' AND ', $pageWhere) . '
                 ORDER BY COALESCE(p.published_at, p.created_at) DESC
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
            'SELECT p.id, p.title, p.slug, p.is_published, p.published_at, p.channel_id
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
               AND p.is_published = :is_published
               AND p.slug IN (:slug_home, :slug_index)
             ORDER BY p.channel_id ASC,
                      CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                      p.published_at DESC'
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
                i.is_preview AS image_is_preview,
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
        $content = (string) ($data['content'] ?? '');
        $extendedBlocks = $this->normalizeExtendedBlocks($data['extended_blocks'] ?? []);
        $extended = $this->encodeExtendedBlocks($extendedBlocks);
        $description = (string) ($data['description'] ?? '');
        $displayTitle = !array_key_exists('display_title', $data) || !empty($data['display_title']) ? 1 : 0;
        $galleryEnabled = !empty($data['gallery_enabled']) ? 1 : 0;
        $isPublished = !empty($data['is_published']) ? 1 : 0;
        $authorUserId = isset($data['author_user_id']) ? (int) $data['author_user_id'] : 0;
        if ($authorUserId < 1) {
            $authorUserId = null;
        }
        $now = gmdate('Y-m-d H:i:s');
        $publishedAt = $isPublished ? ($data['published_at'] ?? $now) : null;
        $categoryIds = $this->categoryEnabled ? $this->normalizeIds($data['category_ids'] ?? []) : [];
        $tagIds = $this->tagEnabled ? $this->normalizeIds($data['tag_ids'] ?? []) : [];

        // Optional channel binding by slug; null keeps page at root URLs.
        $channelId = null;
        if (!empty($data['channel_slug'])) {
            $channelId = $this->channelIdBySlug((string) $data['channel_slug']);
        }

        if ($slug === '') {
            throw new \RuntimeException('Page slug is required.');
        }

        // Path uniqueness is scoped to (channel, slug) pairs.
        if ($this->pathExists($slug, $channelId, $id > 0 ? $id : null)) {
            throw new \RuntimeException('A page already exists for that slug/channel path.');
        }

        // Persist page row + taxonomy assignments as one atomic unit.
        $this->db->beginTransaction();

        try {
            if ($id > 0) {
                // Update existing page row in place and keep immutable created_at untouched.
                $stmt = $this->db->prepare(
                    'UPDATE ' . $pages . '
                     SET title = :title,
                         slug = :slug,
                         content = :content,
                         extended = :extended,
                         description = :description,
                         display_title = :display_title,
                         gallery_enabled = :gallery_enabled,
                         author_user_id = :author_user_id,
                         channel_id = :channel_id,
                         is_published = :is_published,
                         published_at = :published_at,
                         updated_at = :updated_at
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':title' => $title,
                    ':slug' => $slug,
                    ':content' => $content,
                    ':extended' => $extended,
                    ':description' => $description,
                    ':display_title' => $displayTitle,
                    ':gallery_enabled' => $galleryEnabled,
                    ':author_user_id' => $authorUserId,
                    ':channel_id' => $channelId,
                    ':is_published' => $isPublished,
                    ':published_at' => $publishedAt,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);

                $pageId = $id;
            } else {
                // Create path always stores created_at and updated_at together.
                $stmt = $this->db->prepare(
                    'INSERT INTO ' . $pages . '
                    (title, slug, content, extended, description, display_title, gallery_enabled, channel_id, is_published, published_at, author_user_id, created_at, updated_at)
                    VALUES (:title, :slug, :content, :extended, :description, :display_title, :gallery_enabled, :channel_id, :is_published, :published_at, :author_user_id, :created_at, :updated_at)'
                );

                $stmt->execute([
                    ':title' => $title,
                    ':slug' => $slug,
                    ':content' => $content,
                    ':extended' => $extended,
                    ':description' => $description,
                    ':display_title' => $displayTitle,
                    ':gallery_enabled' => $galleryEnabled,
                    ':channel_id' => $channelId,
                    ':is_published' => $isPublished,
                    ':published_at' => $publishedAt,
                    ':author_user_id' => $authorUserId,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $pageId = (int) $this->db->lastInsertId();
            }

            // Replace-all strategy keeps assignments deterministic from form payload.
            if ($this->categoryEnabled) {
                $this->replacePageCategories($pageId, $categoryIds);
            }
            if ($this->tagEnabled) {
                $this->replacePageTags($pageId, $tagIds);
            }

            $this->db->commit();
            return $pageId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
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
    public function assignedCategoriesForPage(int $pageId): array
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
    public function assignedTagsForPage(int $pageId): array
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
    public function taxonomyAssignmentsForPages(array $pageIds): array
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
        $pages = $this->table('pages');
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');
        $pageImages = $this->table('page_images');
        $pageImageVariants = $this->table('page_image_variants');

        $this->db->beginTransaction();

        try {
            // Remove category links so no orphaned relations remain.
            if ($this->categoryEnabled) {
                $detachCategories = $this->db->prepare(
                    'DELETE FROM ' . $pageCategories . ' WHERE page_id = :page_id'
                );
                $detachCategories->execute([':page_id' => $id]);
            }

            // Remove tag links before deleting the page row.
            if ($this->tagEnabled) {
                $detachTags = $this->db->prepare(
                    'DELETE FROM ' . $pageTags . ' WHERE page_id = :page_id'
                );
                $detachTags->execute([':page_id' => $id]);
            }

            // Delete image variants first, then image rows, to keep rows consistent.
            $detachImageVariants = $this->db->prepare(
                'DELETE FROM ' . $pageImageVariants . '
                 WHERE image_id IN (
                    SELECT id FROM ' . $pageImages . ' WHERE page_id = :page_id
                 )'
            );
            $detachImageVariants->execute([':page_id' => $id]);

            $detachImages = $this->db->prepare(
                'DELETE FROM ' . $pageImages . ' WHERE page_id = :page_id'
            );
            $detachImages->execute([':page_id' => $id]);

            $delete = $this->db->prepare('DELETE FROM ' . $pages . ' WHERE id = :id');
            $delete->execute([':id' => $id]);

            // Commit only after relation cleanup and page delete both succeed.
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
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

        $pages = $this->table('pages');
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');

        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                INNER JOIN ' . $pageCategories . ' pc ON pc.page_id = p.id
                INNER JOIN ' . $categories . ' c ON c.id = pc.category_id
                WHERE c.slug = :slug AND p.is_published = :is_published
                ORDER BY p.published_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset';

        // Join table enforces category membership while keeping page rows canonical.
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
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
     * Counts total pages linked to a category slug.
     */
    public function countByCategorySlug(string $slug): int
    {
        if (!$this->categoryEnabled) {
            return 0;
        }

        $pages = $this->table('pages');
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pages . ' p
             INNER JOIN ' . $pageCategories . ' pc ON pc.page_id = p.id
             INNER JOIN ' . $categories . ' c ON c.id = pc.category_id
             WHERE c.slug = :slug AND p.is_published = :is_published'
        );

        $stmt->execute([
            ':slug' => $slug,
            ':is_published' => 1,
        ]);

        return (int) $stmt->fetchColumn();
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

        $pages = $this->table('pages');
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $sql = 'SELECT p.*
                FROM ' . $pages . ' p
                INNER JOIN ' . $pageTags . ' pt ON pt.page_id = p.id
                INNER JOIN ' . $tags . ' t ON t.id = pt.tag_id
                WHERE t.slug = :slug AND p.is_published = :is_published
                ORDER BY p.published_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset';

        // Join table enforces tag membership while keeping page rows canonical.
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
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
     * Counts total pages linked to a tag slug.
     */
    public function countByTagSlug(string $slug): int
    {
        if (!$this->tagEnabled) {
            return 0;
        }

        $pages = $this->table('pages');
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pages . ' p
             INNER JOIN ' . $pageTags . ' pt ON pt.page_id = p.id
             INNER JOIN ' . $tags . ' t ON t.id = pt.tag_id
             WHERE t.slug = :slug AND p.is_published = :is_published'
        );

        $stmt->execute([
            ':slug' => $slug,
            ':is_published' => 1,
        ]);

        return (int) $stmt->fetchColumn();
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

        $pages = $this->table('pages');
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $pages . ' p
             INNER JOIN ' . $pageCategories . ' pc ON pc.page_id = p.id
             INNER JOIN ' . $categories . ' c ON c.id = pc.category_id
             WHERE c.slug = :slug
               AND p.is_published = :is_published
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
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
            $resultRows[] = $this->withChannelContext($this->hydratePageRow($row), null, $channelsById);
        }

        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->countByCategorySlug($slug);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
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

        $pages = $this->table('pages');
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT p.*, COUNT(*) OVER() AS total_rows
             FROM ' . $pages . ' p
             INNER JOIN ' . $pageTags . ' pt ON pt.page_id = p.id
             INNER JOIN ' . $tags . ' t ON t.id = pt.tag_id
             WHERE t.slug = :slug
               AND p.is_published = :is_published
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':is_published', 1, PDO::PARAM_INT);
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
            $resultRows[] = $this->withChannelContext($this->hydratePageRow($row), null, $channelsById);
        }

        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->countByTagSlug($slug);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Hydrates page row with repeatable Extended block metadata.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePageRow(array $row): array
    {
        $rawExtended = (string) ($row['extended'] ?? '');
        $extendedBlocks = $this->decodeExtendedBlocks($rawExtended);
        $row['extended_blocks'] = $extendedBlocks;

        return $row;
    }

    /**
     * Normalizes body-block payload into typed, persistable rows.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function normalizeExtendedBlocks(mixed $raw): array
    {
        return $this->bodyBlockCodec->normalizeStoredBlocks($raw);
    }

    /**
     * Encodes extended blocks as JSON for DB persistence.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
     */
    private function encodeExtendedBlocks(array $blocks): string
    {
        return $this->bodyBlockCodec->encodeStoredBlocks($blocks);
    }

    /**
     * Decodes stored extended JSON payload into typed body blocks.
     *
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    private function decodeExtendedBlocks(string $raw): array
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
        return ChannelContextService::channelsByIdMap($this->channels->listRecords());
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
        return ChannelContextService::resolveChannelIdBySlug(
            $slug,
            fn (string $normalized): ?int => $this->channels->idBySlug($normalized),
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
            fn (string $slug): ?int => $this->channels->idBySlug($slug),
            $placeholderPrefix,
            $includeCategoryFilters,
            $includeTagFilters
        );
    }
}
