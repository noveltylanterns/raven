<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/ChannelRecordScribe.php
 * Channel write orchestration above the low-level channel file scribe.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

use Closure;
use PDO;
use Raven\Lib\Database\TableNameResolver;
use Raven\Lib\Parser\ChannelRepoParser;
use RuntimeException;

/**
 * Owns channel mutation policy and multi-store write orchestration.
 *
 * `ChannelScribe` stays focused on raw file persistence and canonicalization,
 * while this class centralizes the higher-level save/update/delete rules that
 * merge stored channel metadata, enforce reserved root-channel constraints, and
 * coordinate the small DB cleanup step needed before channel deletion.
 */
final class ChannelRecordScribe
{
    private PDO $db;
    private string $driver;
    private string $prefix;
    /** @var Closure(string): array<string, mixed> */
    private Closure $loadRawChannelBySlug;
    private ChannelScribe $channelFileScribe;

    /**
     * Prepares the channel write orchestrator.
     *
     * @param PDO                                   $db                   App database connection used for channel delete cleanup.
     * @param string                                $driver               Active PDO driver name for table resolution.
     * @param string                                $prefix               Application table prefix before sanitization.
     * @param callable(string): array<string, mixed> $loadRawChannelBySlug Read-side loader used to fetch existing raw channel payloads.
     * @param ChannelScribe                         $channelFileScribe    Low-level file scribe used to persist canonical channel files.
     */
    public function __construct(
        PDO $db,
        string $driver,
        string $prefix,
        callable $loadRawChannelBySlug,
        ChannelScribe $channelFileScribe
    ) {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
        $this->loadRawChannelBySlug = Closure::fromCallable($loadRawChannelBySlug);
        $this->channelFileScribe = $channelFileScribe;
    }

    /**
     * Creates or updates one channel and returns its id.
     *
     * @param array{
     *   id: int|null,
     *   name: string,
     *   slug: string,
     *   description: string,
     *   feed_enabled?: bool,
     *   category_sets?: array<int, int|string>,
     *   tag_sets?: array<int, int|string>,
     *   editor_override?: string,
     *   route_mode?: string,
     *   route_separator?: string
     * }                           $data            Normalized channel payload submitted from the repository/controller.
     * @param callable(string): ?array<string, mixed> $findBySlug      Callback for resolving an existing channel by slug.
     * @param callable(int): ?array<string, mixed>    $findById        Callback for resolving an existing channel by id.
     * @param callable(): int                         $nextChannelId   Callback for allocating the next channel id when creating a new channel.
     * @throws RuntimeException When required fields are missing, the root channel is targeted, or the slug is already in use.
     * @return int Persisted channel id.
     */
    public function save(
        array $data,
        callable $findBySlug,
        callable $findById,
        callable $nextChannelId
    ): int {
        $idProvided = array_key_exists('id', $data) && $data['id'] !== null;
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $name = trim((string) ($data['name'] ?? ''));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $editorOverride = ChannelRepoParser::normalizeEditorOverride((string) ($data['editor_override'] ?? 'inherit'));
        $routeMode = ChannelRepoParser::normalizeRouteMode((string) ($data['route_mode'] ?? 'inherit'));
        $routeSeparator = ChannelRepoParser::normalizeRouteSeparator((string) ($data['route_separator'] ?? 'inherit'));

        if ($name === '' || !ChannelRepoParser::isValidSlug($slug)) {
            throw new RuntimeException('Channel name and slug are required.');
        }

        if (ChannelRepoParser::isRootChannelSlug($slug) || ($idProvided && ChannelRepoParser::isRootChannelId($id))) {
            throw new RuntimeException('The stock <root> channel is reserved and cannot be edited.');
        }

        $existingBySlug = $findBySlug($slug);
        if (is_array($existingBySlug) && (int) ($existingBySlug['id'] ?? 0) !== $id) {
            throw new RuntimeException('A channel with that slug already exists.');
        }

        $existingRecord = $idProvided ? $findById($id) : null;
        $oldSlug = is_array($existingRecord) ? (string) ($existingRecord['slug'] ?? '') : '';
        $channelId = is_array($existingRecord)
            ? (int) ($existingRecord['id'] ?? 0)
            : (int) $nextChannelId();

        $currentRaw = $oldSlug !== '' ? $this->loadRawBySlug($oldSlug) : [];
        $customFields = is_array($currentRaw['custom_fields'] ?? null) ? $currentRaw['custom_fields'] : [];
        $overrides = is_array($currentRaw['overrides'] ?? null) ? $currentRaw['overrides'] : [];
        $feedEnabled = array_key_exists('feed_enabled', $data)
            ? ChannelRepoParser::normalizeFeedEnabled($data['feed_enabled'])
            : ChannelRepoParser::normalizeFeedEnabled($currentRaw['feed_enabled'] ?? false);
        $categorySets = array_key_exists('category_sets', $data)
            ? ChannelRepoParser::normalizeTaxonomySetSelection($data['category_sets'], false)
            : ChannelRepoParser::normalizeTaxonomySetSelection($currentRaw['category_sets'] ?? [], false);
        $tagSets = array_key_exists('tag_sets', $data)
            ? ChannelRepoParser::normalizeTaxonomySetSelection($data['tag_sets'], false)
            : ChannelRepoParser::normalizeTaxonomySetSelection($currentRaw['tag_sets'] ?? [], false);
        $createdAt = trim((string) ($currentRaw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => $channelId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'feed_enabled' => $feedEnabled,
            'category_sets' => $categorySets,
            'tag_sets' => $tagSets,
            'editor_override' => $editorOverride,
            'route_mode' => $routeMode,
            'route_separator' => $routeSeparator,
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($currentRaw['preview_image_lg_path'] ?? null),
            'custom_fields' => $customFields,
            'overrides' => $overrides,
            'created_at' => $createdAt,
        ];

        $this->channelFileScribe->writeRecordById($channelId, $slug, $record);

        return $channelId;
    }

    /**
     * Updates one channel's stored image-path set.
     *
     * @param int                                          $id        Channel id to update.
     * @param array{
     *   cover_image_path: string|null,
     *   cover_image_sm_path: string|null,
     *   cover_image_md_path: string|null,
     *   cover_image_lg_path: string|null,
     *   preview_image_path: string|null,
     *   preview_image_sm_path: string|null,
     *   preview_image_md_path: string|null,
     *   preview_image_lg_path: string|null
     * }                                                   $paths     Image-path payload to persist.
     * @param callable(int): ?array<string, mixed>         $findById  Callback for resolving the current channel row by id.
     * @throws RuntimeException When the channel does not exist or has an invalid slug.
     * @return void
     */
    public function updateImagePaths(int $id, array $paths, callable $findById): void
    {
        $record = $findById($id);
        if (!is_array($record)) {
            throw new RuntimeException('Channel not found.');
        }

        $slug = (string) ($record['slug'] ?? '');
        if ($slug === '') {
            throw new RuntimeException('Channel slug is invalid.');
        }

        $currentRaw = $this->loadRawBySlug($slug);
        $raw = [
            'id' => (int) ($record['id'] ?? $id),
            'name' => (string) ($record['name'] ?? ''),
            'slug' => $slug,
            'description' => (string) ($record['description'] ?? ''),
            'feed_enabled' => ChannelRepoParser::normalizeFeedEnabled(
                $currentRaw['feed_enabled'] ?? ($record['feed_enabled'] ?? false)
            ),
            'category_sets' => ChannelRepoParser::normalizeTaxonomySetSelection(
                $currentRaw['category_sets'] ?? ($record['category_sets'] ?? []),
                false
            ),
            'tag_sets' => ChannelRepoParser::normalizeTaxonomySetSelection(
                $currentRaw['tag_sets'] ?? ($record['tag_sets'] ?? []),
                false
            ),
            'editor_override' => (string) ($record['editor_override'] ?? 'inherit'),
            'route_mode' => (string) ($record['route_mode'] ?? 'inherit'),
            'route_separator' => (string) ($record['route_separator'] ?? 'inherit'),
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($paths['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($paths['preview_image_lg_path'] ?? null),
            'custom_fields' => is_array($currentRaw['custom_fields'] ?? null) ? $currentRaw['custom_fields'] : [],
            'overrides' => is_array($currentRaw['overrides'] ?? null) ? $currentRaw['overrides'] : [],
            'created_at' => trim((string) ($currentRaw['created_at'] ?? '')) !== ''
                ? (string) $currentRaw['created_at']
                : gmdate('Y-m-d H:i:s'),
        ];

        $this->channelFileScribe->writeRecordById((int) ($record['id'] ?? $id), $slug, $raw);
    }

    /**
     * Deletes one channel after reassigning any lingering page/redirect rows to the root channel.
     *
     * @param int                                  $id                    Channel id to delete.
     * @param callable(int): ?array<string, mixed> $findById              Callback for resolving the current channel row by id.
     * @param callable(): array<int, int>          $pageCountsByChannelId Callback for resolving current page counts by channel id.
     * @throws RuntimeException When the root channel is targeted or pages are still assigned to the channel.
     * @return void
     */
    public function deleteById(int $id, callable $findById, callable $pageCountsByChannelId): void
    {
        if (ChannelRepoParser::isRootChannelId($id)) {
            throw new RuntimeException('The stock <root> channel cannot be deleted.');
        }

        $record = $findById($id);
        if (!is_array($record)) {
            return;
        }

        $pageCounts = $pageCountsByChannelId();
        if ((int) ($pageCounts[$id] ?? 0) > 0) {
            throw new RuntimeException('Cannot delete a channel that has pages assigned to it.');
        }

        $pages = $this->table('pages');
        $redirects = $this->table('redirects');

        $this->db->beginTransaction();
        try {
            // Reassign any stray rows to root before the channel file disappears.
            $detachPages = $this->db->prepare(
                'UPDATE ' . $pages . ' SET channel = :root_channel WHERE channel = :channel_id'
            );
            $detachPages->execute([
                ':root_channel' => ChannelRepoParser::ROOT_CHANNEL_ID,
                ':channel_id' => $id,
            ]);

            $detachRedirects = $this->db->prepare(
                'UPDATE ' . $redirects . ' SET channel = :root_channel WHERE channel = :channel_id'
            );
            $detachRedirects->execute([
                ':root_channel' => ChannelRepoParser::ROOT_CHANNEL_ID,
                ':channel_id' => $id,
            ]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        $this->channelFileScribe->deleteById($id);
    }

    /**
     * Ensures the stock root channel exists with canonical immutable fields.
     *
     * @throws RuntimeException When the root channel file cannot be persisted.
     * @return void
     */
    public function ensureRootChannelRecord(): void
    {
        $raw = $this->loadRawBySlug(ChannelRepoParser::ROOT_CHANNEL_SLUG);
        $createdAt = trim((string) ($raw['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => ChannelRepoParser::ROOT_CHANNEL_ID,
            'name' => ChannelRepoParser::ROOT_CHANNEL_NAME,
            'slug' => ChannelRepoParser::ROOT_CHANNEL_SLUG,
            'description' => trim((string) ($raw['description'] ?? '')),
            'feed_enabled' => false,
            'editor_override' => ChannelRepoParser::normalizeEditorOverride(
                (string) ($raw['editor_override'] ?? 'inherit')
            ),
            'route_mode' => ChannelRepoParser::normalizeRouteMode((string) ($raw['route_mode'] ?? 'inherit')),
            'route_separator' => ChannelRepoParser::normalizeRouteSeparator(
                (string) ($raw['route_separator'] ?? 'inherit')
            ),
            'cover_image_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_path'] ?? null),
            'cover_image_sm_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_sm_path'] ?? null),
            'cover_image_md_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_md_path'] ?? null),
            'cover_image_lg_path' => ChannelRepoParser::normalizeNullablePath($raw['cover_image_lg_path'] ?? null),
            'preview_image_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_path'] ?? null),
            'preview_image_sm_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_sm_path'] ?? null),
            'preview_image_md_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_md_path'] ?? null),
            'preview_image_lg_path' => ChannelRepoParser::normalizeNullablePath($raw['preview_image_lg_path'] ?? null),
            'custom_fields' => is_array($raw['custom_fields'] ?? null) ? $raw['custom_fields'] : [],
            'overrides' => is_array($raw['overrides'] ?? null) ? $raw['overrides'] : [],
            'created_at' => $createdAt,
        ];

        if ($raw === [] || $this->rootRecordNeedsRewrite($raw)) {
            $this->channelFileScribe->writeRecordById(
                ChannelRepoParser::ROOT_CHANNEL_ID,
                ChannelRepoParser::ROOT_CHANNEL_SLUG,
                $record
            );
        }
    }

    /**
     * Re-persists one channel id into its canonical file when read-side repair assigns a missing id.
     *
     * @param string $slug Channel slug to locate.
     * @param int    $id   Channel id to persist.
     * @return void
     */
    public function persistChannelId(string $slug, int $id): void
    {
        try {
            $this->channelFileScribe->persistChannelId($slug, $id);
        } catch (\Throwable) {
            // Read paths should stay resilient even if best-effort id repair cannot be persisted.
        }
    }

    /**
     * Returns whether the stored root-channel record needs to be rewritten.
     *
     * @param array<string, mixed> $raw Raw root-channel payload from disk.
     * @return bool True when immutable root fields differ from canonical values.
     */
    private function rootRecordNeedsRewrite(array $raw): bool
    {
        if (ChannelRepoParser::normalizeChannelId($raw['id'] ?? null) !== ChannelRepoParser::ROOT_CHANNEL_ID) {
            return true;
        }

        if (!ChannelRepoParser::isRootChannelSlug((string) ($raw['slug'] ?? ''))) {
            return true;
        }

        return trim((string) ($raw['name'] ?? '')) !== ChannelRepoParser::ROOT_CHANNEL_NAME;
    }

    /**
     * Loads the current raw file-backed channel payload by slug.
     *
     * @param string $slug Channel slug to read.
     * @return array<string, mixed> Raw persisted channel payload, or [] when not found.
     */
    private function loadRawBySlug(string $slug): array
    {
        $load = $this->loadRawChannelBySlug;
        $raw = $load($slug);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Maps logical table names into backend-specific physical names.
     *
     * @param string $table Logical unprefixed table name.
     * @return string Physical table name for the active backend.
     */
    private function table(string $table): string
    {
        return TableNameResolver::appTable($this->driver, $this->prefix, $table);
    }
}
