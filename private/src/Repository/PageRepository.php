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
     * Returns paginated page list for panel page index.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0): array
    {
        $pages = $this->table('pages');
        $channels = $this->table('channels');

        $sql = 'SELECT p.id, p.title, p.slug, p.is_published, p.published_at,
                       c.slug AS channel_slug
                FROM ' . $pages . ' p
                LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
                ORDER BY COALESCE(p.published_at, p.created_at) DESC
                LIMIT :limit OFFSET :offset';

        // Bind as ints to avoid backend-specific LIMIT/OFFSET casting quirks.
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns all pages with channel context for routing inventory screens.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllForRouting(): array
    {
        $pages = $this->table('pages');
        $channels = $this->table('channels');

        $stmt = $this->db->prepare(
            'SELECT p.id, p.title, p.slug, p.is_published,
                    c.id AS channel_id, c.slug AS channel_slug, c.name AS channel_name
             FROM ' . $pages . ' p
             LEFT JOIN ' . $channels . ' c ON c.id = p.channel_id
             ORDER BY COALESCE(c.slug, \'\') ASC, p.slug ASC, p.id ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
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

        $placeholders = implode(', ', array_fill(0, count($normalizedPageIds), '?'));
        $pageCategories = $this->table('page_categories');
        $pageTags = $this->table('page_tags');

        $categoryStmt = $this->db->prepare(
            'SELECT page_id, category_id
             FROM ' . $pageCategories . '
             WHERE page_id IN (' . $placeholders . ')'
        );
        $categoryStmt->execute($normalizedPageIds);
        foreach ($categoryStmt->fetchAll() ?: [] as $row) {
            $pageId = (int) ($row['page_id'] ?? 0);
            $categoryId = (int) ($row['category_id'] ?? 0);
            if ($pageId < 1 || $categoryId < 1 || !isset($result[$pageId])) {
                continue;
            }

            $result[$pageId]['categories'][$categoryId] = $categoryId;
        }

        $tagStmt = $this->db->prepare(
            'SELECT page_id, tag_id
             FROM ' . $pageTags . '
             WHERE page_id IN (' . $placeholders . ')'
        );
        $tagStmt->execute($normalizedPageIds);
        foreach ($tagStmt->fetchAll() ?: [] as $row) {
            $pageId = (int) ($row['page_id'] ?? 0);
            $tagId = (int) ($row['tag_id'] ?? 0);
            if ($pageId < 1 || $tagId < 1 || !isset($result[$pageId])) {
                continue;
            }

            $result[$pageId]['tags'][$tagId] = $tagId;
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
        // Preserve a flat field for compatibility with templates still reading `$page['extended']`.
        $row['extended'] = $extendedBlocks === [] ? '' : implode("\n\n", $extendedBlocks);

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
}
