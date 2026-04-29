<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/ChannelWrite.php
 * Write-side data access for filesystem-backed channel metadata (INSERT, UPDATE, DELETE).
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Scribe\ChannelRecordScribe;
use Raven\Lib\Scribe\ChannelScribe;

/**
 * INSERT, UPDATE, and DELETE methods for channel records.
 *
 * Read operations (SELECT, lookup) live in ChannelRead, which is injected here
 * so that write-side validation can perform existence lookups without duplicating queries.
 * After each mutation, calls $read->clearCache() so subsequent reads reflect disk state.
 */
final class ChannelWrite
{
    private ChannelRead $read;
    private ChannelRecordScribe $channelRecordScribe;

    /**
     * @param PDO         $db               Active database connection.
     * @param string      $driver           Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string      $prefix           Table name prefix for this Raven installation.
     * @param ChannelRead $read             Read-side instance for existence lookups and cache invalidation.
     * @param string|null $channelDirectory Absolute path to the channel file directory; defaults to private/dat/channel.
     */
    public function __construct(PDO $db, string $driver, string $prefix, ChannelRead $read, ?string $channelDirectory = null)
    {
        $this->read = $read;
        $resolvedDir = $channelDirectory ?? (dirname(__DIR__, 3) . '/dat/channel');
        $channelFileScribe = new ChannelScribe($resolvedDir);
        $this->channelRecordScribe = new ChannelRecordScribe(
            $db,
            $driver,
            $prefix,
            fn (string $slug): array => $this->read->loadRawBySlug($slug),
            $channelFileScribe
        );
    }

    /**
     * Creates or updates one channel and returns the channel id.
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
     * } $data Normalized channel fields.
     * @return int The saved channel id.
     */
    public function save(array $data): int
    {
        $channelId = $this->channelRecordScribe->save(
            $data,
            fn (string $slug): ?array => $this->read->findBySlug($slug),
            fn (int $id): ?array => $this->read->findById($id),
            fn (): int => $this->nextChannelId()
        );
        $this->read->clearCache();
        return $channelId;
    }

    /**
     * Updates one channel's cover/preview image path set.
     *
     * @param int   $id    Channel id to update.
     * @param array{
     *   cover_image_path: string|null,
     *   cover_image_sm_path: string|null,
     *   cover_image_md_path: string|null,
     *   cover_image_lg_path: string|null,
     *   preview_image_path: string|null,
     *   preview_image_sm_path: string|null,
     *   preview_image_md_path: string|null,
     *   preview_image_lg_path: string|null
     * } $paths Image path strings keyed by size variant.
     */
    public function updateImagePaths(int $id, array $paths): void
    {
        $this->channelRecordScribe->updateImagePaths(
            $id,
            $paths,
            fn (int $channelId): ?array => $this->read->findById($channelId)
        );
        $this->read->clearCache();
    }

    /**
     * Deletes one channel. Throws if the channel still has pages or redirects assigned.
     *
     * @param int $id Channel id to delete.
     * @throws \RuntimeException When the channel has associated pages or cannot be removed.
     */
    public function deleteById(int $id): void
    {
        $this->channelRecordScribe->deleteById(
            $id,
            fn (int $channelId): ?array => $this->read->findById($channelId),
            fn (): array => $this->read->pageCountsByChannelId()
        );
        $this->read->clearCache();
    }

    /**
     * Returns the next channel id as max(existing ids) + 1.
     *
     * @return int Next sequential channel id.
     */
    private function nextChannelId(): int
    {
        $maxId = 0;
        foreach ($this->read->listRecords() as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId > $maxId) {
                $maxId = $recordId;
            }
        }

        return $maxId + 1;
    }
}
