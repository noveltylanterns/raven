<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/TagRepoParser.php
 * Repository-backed tag lookup parser for routing and public tag reads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Media\PreviewConfig;

/**
 * Repository-backed parser for tag lookup rows and routing option payloads.
 */
final class TagRepoParser
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * Initializes the tag lookup parser.
     *
     * @param PDO    $db     App database connection used for tag lookup reads.
     * @param string $driver Active PDO driver name used for table-name resolution.
     * @param string $prefix Application table prefix before resolver sanitization.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Finds one tag row by slug for public-route resolution.
     *
     * @param string $slug Normalized tag slug.
     * @return array<string, mixed>|null Tag row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
                id,
                name,
                slug,
                description,
                cover_image,
                preview_image
             FROM ' . $this->table('tags') . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrateTagRow($row);
    }

    /**
     * Returns lightweight tag routing options for mixed routing inventories.
     *
     * @return array<int, array{id: int, name: string, slug: string}> Tag routing option rows.
     */
    public function listRoutingOptions(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, slug
             FROM ' . $this->table('tags') . '
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($id < 1 || $slug === '') {
                continue;
            }

            $result[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'slug' => $slug,
            ];
        }

        return $result;
    }

    /**
     * Maps one logical table name into the backend-specific physical table name.
     *
     * @param string $table Logical unprefixed table name.
     * @return string       Physical table name for the active database backend.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Hydrates one raw tag row with taxonomy image URLs.
     *
     * @param array<string, mixed> $row Raw tag database row.
     * @return array<string, mixed> Tag row with storage-backed image URLs.
     */
    private function hydrateTagRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $storage = PreviewConfig::storagePayloadFromRecord('tag', $row);
        if (PreviewConfig::supportsFilenameStorage('tag')) {
            $row['cover_image'] = $storage['cover_image'] ?? null;
            $row['preview_image'] = $storage['preview_image'] ?? null;
        }

        return array_merge($row, PreviewConfig::pathsFromStoragePayload('tag', $id, $storage));
    }
}
