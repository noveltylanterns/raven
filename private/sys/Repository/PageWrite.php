<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/PageWrite.php
 * Write-side data access for page records (INSERT, UPDATE, DELETE, schedule flipping).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use RuntimeException;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Core\Repository\ChannelShared;
use Raven\Core\Debug\UniquenessProfiler;
use Raven\Lib\Parser\PageBlockParser;
use Raven\Core\Repository\PageShared;
use Raven\Lib\Database\SqlInsert;
use Raven\Lib\Database\SqlTable;

/**
 * INSERT, UPDATE, and DELETE methods for page records.
 *
 * Read operations (SELECT, lookup, taxonomy listing) live in PageRead.
 * Schedule flipping (applySchedule) lives in Scheduler\Queue so public routes
 * can call it without loading this class.
 * SQL mutations are owned directly by this repository write seam.
 */
final class PageWrite
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $channelRepo;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private SqlInsert $insertSql;

    /**
     * @param PDO         $db               Active database connection.
     * @param string      $driver           Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix           Table name prefix for this Raven installation.
     * @param ChannelRead $channelRepo      Channel read-side repository for channel slug resolution.
     * @param bool        $categoryEnabled  Whether category taxonomy support is active.
     * @param bool        $tagEnabled       Whether tag taxonomy support is active.
     * @return void
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
        $this->insertSql = new SqlInsert();
    }

    /**
     * Creates or updates a page row from a normalized form payload.
     *
     * Validates slug presence, optional channel slug binding, and path uniqueness
     * before executing the actual INSERT/UPDATE persistence.
     *
     * @param array<string, mixed> $data Normalized page fields from any caller (panel, CLI, or extension).
     * @return int The saved page id (inserted id on create, passed id on update).
     * @throws RuntimeException When slug is missing, the channel slug is invalid, or the path already exists.
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
        $categoryIds = $this->categoryEnabled ? PageShared::normalizeIds($data['category_ids'] ?? []) : [];
        $tagIds = $this->tagEnabled ? PageShared::normalizeIds($data['tag_ids'] ?? []) : [];

        // Optional channel binding by slug; channel id `0` is the stock root scope.
        $channelId = 0;
        if (!empty($data['channel_slug'])) {
            $channelId = $this->channelIdBySlug((string) $data['channel_slug']);
            if ($channelId !== null && $channelId < 1) {
                throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
            }
        }

        if ($slug === '') {
            throw new RuntimeException('Page slug is required.');
        }

        // Path uniqueness is scoped to (channel, slug) pairs.
        if ($this->pathExists($slug, $channelId, $id > 0 ? $id : null)) {
            throw new RuntimeException('A page already exists for that slug/channel path.');
        }

        return $this->persistPage([
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
     * Deletes one page and clears its category/tag links first.
     *
     * @param int $id Page id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $this->db->beginTransaction();

        try {
            $pageIdParams = [':page' => $id];

            // Delete taxonomy links before removing the page so junction rows
            // never outlive the content row when one statement fails mid-flight.
            foreach ([
                [$this->categoryEnabled, $this->table('page_categories')],
                [$this->tagEnabled, $this->table('page_tags')],
            ] as [$enabled, $table]) {
                if (!$enabled) {
                    continue;
                }

                $detachTaxonomy = $this->db->prepare(
                    'DELETE FROM ' . $table . ' WHERE page = :page'
                );
                $detachTaxonomy->execute($pageIdParams);
            }

            // Variants hang off image ids, so they must be removed before the
            // owning image rows to keep the transaction FK-safe across drivers.
            $detachImageVariants = $this->db->prepare(
                'DELETE FROM ' . $this->table('media_variants') . '
                 WHERE image IN (
                    SELECT id FROM ' . $this->table('media') . ' WHERE page = :page
                 )'
            );
            $detachImageVariants->execute($pageIdParams);

            $detachImages = $this->db->prepare(
                'DELETE FROM ' . $this->table('media') . ' WHERE page = :page'
            );
            $detachImages->execute($pageIdParams);

            // Delete the owning page row last so all cleanup still has access
            // to the source page id throughout the transaction.
            $delete = $this->db->prepare(
                'DELETE FROM ' . $this->table('pages') . ' WHERE id = :id'
            );
            $delete->execute([':id' => $id]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Creates or updates one page row and replaces its taxonomy assignments.
     *
     * @param array{
     *   id: int,
     *   title: string,
     *   slug: string,
     *   content: string,
     *   description: string,
     *   display_title: int,
     *   status: string,
     *   published: string|null,
     *   expires: string|null,
     *   author: int|null,
     *   channel: int|null,
     *   now: string,
     *   category_ids: array<int>,
     *   tag_ids: array<int>
     * } $payload Normalized page payload ready for database persistence.
     * @return int Saved page id.
     * @throws \Throwable Re-throws database or taxonomy-write failures after rollback.
     */
    private function persistPage(array $payload): int
    {
        $pagesTable = $this->table('pages');
        $id = (int) ($payload['id'] ?? 0);
        $now = (string) ($payload['now'] ?? gmdate('Y-m-d H:i:s'));
        $categoryIds = is_array($payload['category_ids'] ?? null) ? $payload['category_ids'] : [];
        $tagIds = is_array($payload['tag_ids'] ?? null) ? $payload['tag_ids'] : [];
        $published = isset($payload['published']) && $payload['published'] !== '' ? (string) $payload['published'] : null;
        $expires = isset($payload['expires']) && $payload['expires'] !== '' ? (string) $payload['expires'] : null;

        $writeParams = [
            ':title' => (string) ($payload['title'] ?? ''),
            ':slug' => (string) ($payload['slug'] ?? ''),
            ':content' => (string) ($payload['content'] ?? ''),
            ':description' => (string) ($payload['description'] ?? ''),
            ':display_title' => (int) ($payload['display_title'] ?? 1),
            ':channel' => $payload['channel'] ?? null,
            ':status' => (string) ($payload['status'] ?? 'draft'),
            ':published' => $published,
            ':expires' => $expires,
            ':author' => $payload['author'] ?? null,
            ':updated' => $now,
        ];

        $this->db->beginTransaction();

        try {
            if ($id > 0) {
                // Updates stay in-place so page ids and related media rows remain stable.
                $stmt = $this->db->prepare(
                    'UPDATE ' . $pagesTable . '
                     SET title = :title,
                         slug = :slug,
                         content = :content,
                         description = :description,
                         display_title = :display_title,
                         author = :author,
                         channel = :channel,
                         status = :status,
                         published = :published,
                         expires = :expires,
                         updated = :updated
                     WHERE id = :id'
                );

                $stmt->execute($writeParams + [':id' => $id]);
                $pageId = $id;
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO ' . $pagesTable . '
                    (title, slug, content, description, display_title, channel, status, published, expires, author, created, updated)
                    VALUES (:title, :slug, :content, :description, :display_title, :channel, :status, :published, :expires, :author, :created, :updated)'
                );

                $stmt->execute($writeParams + [':created' => $now]);
                $pageId = (int) $this->db->lastInsertId();
            }

            if ($this->categoryEnabled) {
                $this->replaceAssignments($this->table('page_categories'), $pageId, 'category', $categoryIds);
            }

            if ($this->tagEnabled) {
                $this->replaceAssignments($this->table('page_tags'), $pageId, 'tag', $tagIds);
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
     * Replaces all assignments for one page/taxonomy link table.
     *
     * @param string     $table  Physical page-taxonomy junction table.
     * @param int        $pageId Owning page id whose assignments are being replaced.
     * @param string     $column Taxonomy foreign-key column name: `category` or `tag`.
     * @param array<int> $ids    Replacement taxonomy ids.
     * @return void
     */
    private function replaceAssignments(string $table, int $pageId, string $column, array $ids): void
    {
        $delete = $this->db->prepare(
            'DELETE FROM ' . $table . ' WHERE page = :page'
        );
        $delete->execute([':page' => $pageId]);

        // A full replace keeps panel saves deterministic: the stored taxonomy
        // set always mirrors the last submitted checkbox state exactly.
        if ($ids === []) {
            return;
        }

        $insert = $this->db->prepare(
            $this->insertSql->insertIgnore(
                $this->driver,
                $table,
                ['page', $column],
                ['page', $column]
            )
        );

        foreach ($ids as $id) {
            $insert->execute([
                ':page' => $pageId,
                ':' . $column => $id,
            ]);
        }
    }

    /**
     * Normalizes a datetime string from panel input to Y-m-d H:i:s DB format, or null.
     *
     * Accepts `Y-m-d H:i:s`, `Y-m-d H:i`, and HTML datetime-local format `Y-m-d\TH:i`.
     *
     * @param mixed $raw Raw datetime value from form input.
     * @return string|null Normalized datetime string, or null when absent or invalid.
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
     * Returns true when another page already uses the same (slug, channel) path scope.
     *
     * @param string   $slug      Page slug being saved.
     * @param int|null $channelId Channel id scope (0 or null = root).
     * @param int|null $excludeId Page id to exclude from the check (the page being updated).
     * @return bool True when the path is already taken by another page.
     */
    private function pathExists(string $slug, ?int $channelId, ?int $excludeId = null): bool
    {
        return UniquenessProfiler::exists(
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
     * Normalizes body-block payload into typed, persistable rows.
     *
     * @param mixed $raw Raw content_blocks value from caller input.
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}> Normalized content blocks.
     */
    private function normalizeContentBlocks(mixed $raw): array
    {
        return (new PageBlockParser())->normalizeStoredBlocks($raw);
    }

    /**
     * Encodes content blocks as JSON for DB persistence.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks Normalized content blocks.
     * @return string JSON-encoded content blocks string.
     */
    private function encodeContentBlocks(array $blocks): string
    {
        return (new PageBlockParser())->encodeStoredBlocks($blocks);
    }

    /**
     * Resolves channel id by slug for page save operations.
     *
     * Throws when a root-channel placeholder slug is passed, since root-scope pages
     * use `channel = 0` implicitly and do not go through this path.
     *
     * @param string $slug Channel slug from form input.
     * @return int|null Resolved channel id, or null when the slug resolves to nothing.
     * @throws RuntimeException When the root-channel placeholder slug is passed directly.
     */
    private function channelIdBySlug(string $slug): ?int
    {
        if (ChannelShared::isRootChannelSlug($slug)) {
            throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
        }

        return ChannelDataParser::resolveChannelIdBySlug(
            $slug,
            fn (string $normalized): ?int => $this->channelRepo->idBySlug($normalized),
            'Selected channel does not exist.'
        );
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical table name.
     * @return string Physical table name for the active driver/prefix.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }
}
