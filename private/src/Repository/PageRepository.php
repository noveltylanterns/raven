<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/PageRepository.php
 * Repository for database persistence operations.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use PDO;

/**
 * Data access for pages and their public listing queries.
 */
final class PageRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is only used in shared-db modes; SQLite uses attached DB names instead.
        $this->prefix = $driver === 'sqlite' ? '' : preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
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
        $channels = $this->table('channels');

        $sql = 'SELECT p.*, c.slug AS channel_slug, c.name AS channel_name
                FROM ' . $pages . ' p
                INNER JOIN ' . $channels . ' c ON c.id = p.channel_id
                WHERE c.slug = :channel_slug
                  AND p.is_published = :is_published
                  AND p.slug IN (:slug_home, :slug_index)
                ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                         p.published_at DESC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':channel_slug' => $channelSlug,
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
     * Finds one public page by slug and optional channel slug.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicPage(string $pageSlug, ?string $channelSlug = null): ?array
    {
        $pages = $this->table('pages');
        $channels = $this->table('channels');

        $sql = 'SELECT p.*, c.slug AS channel_slug, c.name AS channel_name
                FROM ' . $pages . ' p
                LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
                WHERE p.slug = :page_slug
                  AND p.is_published = :is_published';

        $params = [
            ':page_slug' => $pageSlug,
            ':is_published' => 1,
        ];

        // Unchanneled pages resolve at root; channeled pages require explicit channel slug match.
        if ($channelSlug === null) {
            $sql .= ' AND p.channel_id IS NULL';
        } else {
            $sql .= ' AND c.slug = :channel_slug';
            $params[':channel_slug'] = $channelSlug;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->hydratePageRow($row);
    }

    /**
     * Returns one total-count for panel page index with optional prefilters.
     */
    public function countForPanel(?string $channelSlug = null, ?int $categoryId = null, ?int $tagId = null): int
    {
        $pages = $this->table('pages');
        $channels = $this->table('channels');
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
            $pageTags
        );

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $pages . ' p
             LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
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
        $channels = $this->table('channels');
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
            $pageTags
        );

        $stmt = $this->db->prepare(
            'SELECT p.id, p.title, p.slug, p.is_published, p.published_at,
                    c.slug AS channel_slug
             FROM ' . $pages . ' p
             LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
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

        return $stmt->fetchAll() ?: [];
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
        $channels = $this->table('channels');
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
            'page_filter'
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
            'count_filter'
        );

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.title,
                    page_rows.slug,
                    page_rows.is_published,
                    page_rows.published_at,
                    page_rows.channel_slug,
                    totals.total_rows
             FROM (
                 SELECT p.id, p.title, p.slug, p.is_published, p.published_at,
                        c.slug AS channel_slug
                 FROM ' . $pages . ' p
                 LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
                 WHERE ' . implode(' AND ', $pageWhere) . '
                 ORDER BY COALESCE(p.published_at, p.created_at) DESC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $pages . ' p
                 LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
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
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $row;
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
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT c.slug AS channel_slug,
                    (
                        SELECT p.slug
                        FROM ' . $pages . ' p
                        WHERE p.channel_id = c.id
                          AND p.is_published = :is_published
                          AND p.slug IN (:slug_home, :slug_index)
                        ORDER BY CASE p.slug WHEN :slug_home_order THEN 0 ELSE 1 END,
                                 p.published_at DESC
                        LIMIT 1
                    ) AS landing_slug
             FROM ' . $channels . ' c
             ORDER BY c.id ASC'
        );
        $stmt->execute([
            ':is_published' => 1,
            ':slug_home' => 'home',
            ':slug_index' => 'index',
            ':slug_home_order' => 'home',
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $channelSlug = trim((string) ($row['channel_slug'] ?? ''));
            if ($channelSlug === '') {
                continue;
            }

            $result[$channelSlug] = trim((string) ($row['landing_slug'] ?? ''));
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
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT p.*, c.slug AS channel_slug
             FROM ' . $pages . ' p
             LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
             WHERE p.id = :id
             LIMIT 1'
        );

        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydratePageRow($row);
    }

    /**
     * Returns page-edit payload with page row and gallery rows in one query.
     *
     * @return array{page: array<string, mixed>, gallery_images: array<int, array<string, mixed>>}|null
     */
    public function editFormDataById(int $id): ?array
    {
        $pages = $this->table('pages');
        $channels = $this->table('channels');
        $images = $this->table('page_images');
        $variants = $this->table('page_image_variants');

        $stmt = $this->db->prepare(
            'SELECT
                p.*,
                c.slug AS channel_slug,
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
             LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
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
            'page' => $this->hydratePageRow($this->stripEditorMediaColumns($rows[0])),
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
        $galleryEnabled = !empty($data['gallery_enabled']) ? 1 : 0;
        $isPublished = !empty($data['is_published']) ? 1 : 0;
        $now = gmdate('Y-m-d H:i:s');
        $publishedAt = $isPublished ? ($data['published_at'] ?? $now) : null;
        $categoryIds = $this->normalizeIds($data['category_ids'] ?? []);
        $tagIds = $this->normalizeIds($data['tag_ids'] ?? []);

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
                         gallery_enabled = :gallery_enabled,
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
                    ':gallery_enabled' => $galleryEnabled,
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
                    (title, slug, content, extended, description, gallery_enabled, channel_id, is_published, published_at, author_user_id, created_at, updated_at)
                    VALUES (:title, :slug, :content, :extended, :description, :gallery_enabled, :channel_id, :is_published, :published_at, :author_user_id, :created_at, :updated_at)'
                );

                $stmt->execute([
                    ':title' => $title,
                    ':slug' => $slug,
                    ':content' => $content,
                    ':extended' => $extended,
                    ':description' => $description,
                    ':gallery_enabled' => $galleryEnabled,
                    ':channel_id' => $channelId,
                    ':is_published' => $isPublished,
                    ':published_at' => $publishedAt,
                    ':author_user_id' => null,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $pageId = (int) $this->db->lastInsertId();
            }

            // Replace-all strategy keeps assignments deterministic from form payload.
            $this->replacePageCategories($pageId, $categoryIds);
            $this->replacePageTags($pageId, $tagIds);

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
        $pages = $this->table('pages');
        $sql = 'SELECT id
                FROM ' . $pages . '
                WHERE slug = :slug';
        $params = [':slug' => $slug];

        if ($channelId === null) {
            $sql .= ' AND channel_id IS NULL';
        } else {
            $sql .= ' AND channel_id = :channel_id';
            $params[':channel_id'] = $channelId;
        }

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns assigned categories for one page.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function assignedCategoriesForPage(int $pageId): array
    {
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

        $categoryPlaceholders = implode(', ', array_fill(0, count($normalizedPageIds), '?'));
        $tagPlaceholders = implode(', ', array_fill(0, count($normalizedPageIds), '?'));
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');

        $assignmentStmt = $this->db->prepare(
            'SELECT page_id, taxonomy_id, taxonomy_type
             FROM (
                 SELECT page_id, category_id AS taxonomy_id, \'category\' AS taxonomy_type
                 FROM ' . $pageCategories . '
                 WHERE page_id IN (' . $categoryPlaceholders . ')
                 UNION ALL
                 SELECT page_id, tag_id AS taxonomy_id, \'tag\' AS taxonomy_type
                 FROM ' . $pageTags . '
                 WHERE page_id IN (' . $tagPlaceholders . ')
             ) AS assignment_rows'
        );
        $assignmentStmt->execute(array_merge($normalizedPageIds, $normalizedPageIds));
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
            $detachCategories = $this->db->prepare(
                'DELETE FROM ' . $pageCategories . ' WHERE page_id = :page_id'
            );
            $detachCategories->execute([':page_id' => $id]);

            // Remove tag links before deleting the page row.
            $detachTags = $this->db->prepare(
                'DELETE FROM ' . $pageTags . ' WHERE page_id = :page_id'
            );
            $detachTags->execute([':page_id' => $id]);

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
        $pages = $this->table('pages');
        $channels = $this->table('channels');
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');

        $sql = 'SELECT p.*, ch.slug AS channel_slug
                FROM ' . $pages . ' p
                LEFT JOIN ' . $channels . ' ch ON ch.id = p.channel_id
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
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->hydratePageRow($row);
        }

        return $rows;
    }

    /**
     * Counts total pages linked to a category slug.
     */
    public function countByCategorySlug(string $slug): int
    {
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
        $pages = $this->table('pages');
        $channels = $this->table('channels');
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');

        $sql = 'SELECT p.*, ch.slug AS channel_slug
                FROM ' . $pages . ' p
                LEFT JOIN ' . $channels . ' ch ON ch.id = p.channel_id
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
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index] = $this->hydratePageRow($row);
        }

        return $rows;
    }

    /**
     * Counts total pages linked to a tag slug.
     */
    public function countByTagSlug(string $slug): int
    {
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
        $pages = $this->table('pages');
        $channels = $this->table('channels');
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT p.*, ch.slug AS channel_slug, COUNT(*) OVER() AS total_rows
             FROM ' . $pages . ' p
             LEFT JOIN ' . $channels . ' ch ON ch.id = p.channel_id
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
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->hydratePageRow($row);
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
        $pages = $this->table('pages');
        $channels = $this->table('channels');
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT p.*, ch.slug AS channel_slug, COUNT(*) OVER() AS total_rows
             FROM ' . $pages . ' p
             LEFT JOIN ' . $channels . ' ch ON ch.id = p.channel_id
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
        foreach ($rows as $row) {
            if ($total === 0) {
                $total = (int) ($row['total_rows'] ?? 0);
            }

            unset($row['total_rows']);
            $resultRows[] = $this->hydratePageRow($row);
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
     * Normalizes extended-block payload into a compact list of non-empty HTML strings.
     *
     * @return array<int, string>
     */
    private function normalizeExtendedBlocks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $entry) {
            if (!is_scalar($entry) && $entry !== null) {
                continue;
            }

            $html = trim((string) ($entry ?? ''));
            if ($html === '') {
                continue;
            }

            $blocks[] = $html;
        }

        return $blocks;
    }

    /**
     * Encodes extended blocks as JSON for DB persistence.
     *
     * @param array<int, string> $blocks
     */
    private function encodeExtendedBlocks(array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }

        $encoded = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Decodes stored extended JSON payload into a list of HTML blocks.
     *
     * @return array<int, string>
     */
    private function decodeExtendedBlocks(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            // Non-JSON content is treated as one block for safety.
            return [$raw];
        }

        $blocks = [];
        foreach ($decoded as $entry) {
            if (!is_scalar($entry) && $entry !== null) {
                continue;
            }

            $html = trim((string) ($entry ?? ''));
            if ($html === '') {
                continue;
            }

            $blocks[] = $html;
        }

        return $blocks;
    }

    /**
     * Drops media-join columns from combined page-editor row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stripEditorMediaColumns(array $row): array
    {
        foreach (array_keys($row) as $column) {
            $name = (string) $column;
            if (str_starts_with($name, 'image_') || str_starts_with($name, 'variant_')) {
                unset($row[$name]);
            }
        }

        return $row;
    }

    /**
     * Hydrates page-editor image/variant rows from combined query output.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateEditorGalleryRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $imagesById = [];
        $orderedImageIds = [];
        foreach ($rows as $row) {
            $imageId = (int) ($row['image_id'] ?? 0);
            if ($imageId < 1) {
                continue;
            }

            if (!isset($imagesById[$imageId])) {
                $storedPath = (string) ($row['image_stored_path'] ?? '');
                $imagesById[$imageId] = [
                    'id' => $imageId,
                    'page_id' => (int) ($row['image_page_id'] ?? 0),
                    'storage_target' => (string) ($row['image_storage_target'] ?? ''),
                    'original_filename' => (string) ($row['image_original_filename'] ?? ''),
                    'stored_filename' => (string) ($row['image_stored_filename'] ?? ''),
                    'stored_path' => $storedPath,
                    'url' => $this->publicUrlFromStoredPath($storedPath),
                    'mime_type' => (string) ($row['image_mime_type'] ?? ''),
                    'extension' => (string) ($row['image_extension'] ?? ''),
                    'byte_size' => (int) ($row['image_byte_size'] ?? 0),
                    'width' => (int) ($row['image_width'] ?? 0),
                    'height' => (int) ($row['image_height'] ?? 0),
                    'hash_sha256' => (string) ($row['image_hash_sha256'] ?? ''),
                    'status' => (string) ($row['image_status'] ?? ''),
                    'sort_order' => (int) ($row['image_sort_order'] ?? 0),
                    'is_cover' => (int) ($row['image_is_cover'] ?? 0) === 1,
                    'is_preview' => (int) ($row['image_is_preview'] ?? 0) === 1,
                    'include_in_gallery' => (int) ($row['image_include_in_gallery'] ?? 1) === 1,
                    'alt_text' => (string) ($row['image_alt_text'] ?? ''),
                    'title_text' => (string) ($row['image_title_text'] ?? ''),
                    'caption' => (string) ($row['image_caption'] ?? ''),
                    'credit' => (string) ($row['image_credit'] ?? ''),
                    'license' => (string) ($row['image_license'] ?? ''),
                    'focal_x' => $row['image_focal_x'] === null ? null : (float) $row['image_focal_x'],
                    'focal_y' => $row['image_focal_y'] === null ? null : (float) $row['image_focal_y'],
                    'created_at' => (string) ($row['image_created_at'] ?? ''),
                    'updated_at' => (string) ($row['image_updated_at'] ?? ''),
                    'variants' => [],
                ];
                $orderedImageIds[] = $imageId;
            }

            $variantKey = trim((string) ($row['variant_key'] ?? ''));
            if ($variantKey === '') {
                continue;
            }

            $variantStoredPath = (string) ($row['variant_stored_path'] ?? '');
            $imagesById[$imageId]['variants'][$variantKey] = [
                'variant_key' => $variantKey,
                'stored_filename' => (string) ($row['variant_stored_filename'] ?? ''),
                'stored_path' => $variantStoredPath,
                'url' => $this->publicUrlFromStoredPath($variantStoredPath),
                'mime_type' => (string) ($row['variant_mime_type'] ?? ''),
                'extension' => (string) ($row['variant_extension'] ?? ''),
                'byte_size' => (int) ($row['variant_byte_size'] ?? 0),
                'width' => (int) ($row['variant_width'] ?? 0),
                'height' => (int) ($row['variant_height'] ?? 0),
            ];
        }

        $result = [];
        foreach ($orderedImageIds as $imageId) {
            $result[] = $imagesById[$imageId];
        }

        return $result;
    }

    /**
     * Converts one stored relative path into a public URL path.
     */
    private function publicUrlFromStoredPath(string $storedPath): string
    {
        return '/' . ltrim($storedPath, '/');
    }

    /**
     * Resolves channel id by slug for page save operations.
     */
    private function channelIdBySlug(string $slug): ?int
    {
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT id FROM ' . $channels . ' WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Replaces all category assignments for one page id.
     *
     * @param array<int> $categoryIds
     */
    private function replacePageCategories(int $pageId, array $categoryIds): void
    {
        $pageCategories = $this->table('page_categories');

        $delete = $this->db->prepare(
            'DELETE FROM ' . $pageCategories . ' WHERE page_id = :page_id'
        );
        $delete->execute([':page_id' => $pageId]);

        if ($categoryIds === []) {
            return;
        }

        foreach ($categoryIds as $categoryId) {
            if ($this->driver === 'sqlite') {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $pageCategories . ' (page_id, category_id)
                     VALUES (:page_id, :category_id)
                     ON CONFLICT(page_id, category_id) DO NOTHING'
                );
            } elseif ($this->driver === 'mysql') {
                $insert = $this->db->prepare(
                    'INSERT IGNORE INTO ' . $pageCategories . ' (page_id, category_id)
                     VALUES (:page_id, :category_id)'
                );
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $pageCategories . ' (page_id, category_id)
                     VALUES (:page_id, :category_id)
                     ON CONFLICT (page_id, category_id) DO NOTHING'
                );
            }

            $insert->execute([
                ':page_id' => $pageId,
                ':category_id' => $categoryId,
            ]);
        }
    }

    /**
     * Replaces all tag assignments for one page id.
     *
     * @param array<int> $tagIds
     */
    private function replacePageTags(int $pageId, array $tagIds): void
    {
        $pageTags = $this->table('page_tags');

        $delete = $this->db->prepare(
            'DELETE FROM ' . $pageTags . ' WHERE page_id = :page_id'
        );
        $delete->execute([':page_id' => $pageId]);

        if ($tagIds === []) {
            return;
        }

        foreach ($tagIds as $tagId) {
            if ($this->driver === 'sqlite') {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $pageTags . ' (page_id, tag_id)
                     VALUES (:page_id, :tag_id)
                     ON CONFLICT(page_id, tag_id) DO NOTHING'
                );
            } elseif ($this->driver === 'mysql') {
                $insert = $this->db->prepare(
                    'INSERT IGNORE INTO ' . $pageTags . ' (page_id, tag_id)
                     VALUES (:page_id, :tag_id)'
                );
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO ' . $pageTags . ' (page_id, tag_id)
                     VALUES (:page_id, :tag_id)
                     ON CONFLICT (page_id, tag_id) DO NOTHING'
                );
            }

            $insert->execute([
                ':page_id' => $pageId,
                ':tag_id' => $tagId,
            ]);
        }
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
        if ($this->driver !== 'sqlite') {
            // Shared-db mode relies on configurable table prefixes only.
            return $this->prefix . $table;
        }

        // SQLite mode maps logical names onto attached database file aliases.
        return match ($table) {
            'pages' => 'main.pages',
            'channels' => 'taxonomy.channels',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'page_categories' => 'main.page_categories',
            'page_tags' => 'main.page_tags',
            'page_images' => 'main.page_images',
            'page_image_variants' => 'main.page_image_variants',
            default => 'main.' . $table,
        };
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
        string $placeholderPrefix = 'filter'
    ): void {
        $placeholderPrefix = trim($placeholderPrefix);
        if ($placeholderPrefix === '') {
            $placeholderPrefix = 'filter';
        }

        $channelSlugPlaceholder = ':' . $placeholderPrefix . '_channel_slug';
        $categoryIdPlaceholder = ':' . $placeholderPrefix . '_category_id';
        $tagIdPlaceholder = ':' . $placeholderPrefix . '_tag_id';

        $channelSlug = trim((string) ($channelSlug ?? ''));
        if ($channelSlug !== '') {
            $where[] = 'LOWER(COALESCE(c.slug, \'\')) = ' . $channelSlugPlaceholder;
            $params[$channelSlugPlaceholder] = strtolower($channelSlug);
        }

        $categoryId = $categoryId !== null && $categoryId > 0 ? $categoryId : null;
        if ($categoryId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $pageCategoriesTable . ' pc
                WHERE pc.page_id = p.id
                  AND pc.category_id = ' . $categoryIdPlaceholder . '
            )';
            $params[$categoryIdPlaceholder] = $categoryId;
        }

        $tagId = $tagId !== null && $tagId > 0 ? $tagId : null;
        if ($tagId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $pageTagsTable . ' pt
                WHERE pt.page_id = p.id
                  AND pt.tag_id = ' . $tagIdPlaceholder . '
            )';
            $params[$tagIdPlaceholder] = $tagId;
        }
    }
}
