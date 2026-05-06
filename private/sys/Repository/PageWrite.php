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
use Raven\Lib\Parser\ChannelRepoParser;
use Raven\Core\Debug\UniquenessProfiler;
use Raven\Lib\Parser\PageRepoParser;
use Raven\Lib\Scribe\PageScribe;
use Raven\Lib\Database\SqlTable;

/**
 * INSERT, UPDATE, and DELETE methods for page records.
 *
 * Read operations (SELECT, lookup, taxonomy listing) live in PageRead.
 * Schedule flipping (applySchedule) lives in PageRepoParser so public routes
 * can call it without loading this class.
 * All SQL mutations are delegated to PageScribe.
 */
final class PageWrite
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    private ChannelRead $channelRepo;
    private bool $categoryEnabled;
    private bool $tagEnabled;
    private PageScribe $pageScribe;

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
        $this->pageScribe = new PageScribe($db, $driver, $prefix, $categoryEnabled, $tagEnabled);
    }

    /**
     * Creates or updates a page row from a normalized form payload.
     *
     * Validates slug presence, optional channel slug binding, and path uniqueness
     * before delegating the actual INSERT/UPDATE to PageScribe.
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
        $categoryIds = $this->categoryEnabled ? PageRepoParser::normalizeIds($data['category_ids'] ?? []) : [];
        $tagIds = $this->tagEnabled ? PageRepoParser::normalizeIds($data['tag_ids'] ?? []) : [];

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
     * Deletes one page and clears its category/tag links first.
     *
     * @param int $id Page id to delete.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $this->pageScribe->deleteById($id);
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
        return PageRepoParser::normalizeStoredBlocks($raw);
    }

    /**
     * Encodes content blocks as JSON for DB persistence.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks Normalized content blocks.
     * @return string JSON-encoded content blocks string.
     */
    private function encodeContentBlocks(array $blocks): string
    {
        return PageRepoParser::encodeStoredBlocks($blocks);
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
        if (ChannelRepoParser::isRootChannelSlug($slug)) {
            throw new RuntimeException('The stock <root> channel placeholder cannot be selected directly.');
        }

        return ChannelRepoParser::resolveChannelIdBySlug(
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
