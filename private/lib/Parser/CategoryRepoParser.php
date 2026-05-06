<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/CategoryRepoParser.php
 * Repository-backed category lookup parser for routing and public category reads.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Media\PreviewConfig;

/**
 * Repository-backed parser for category lookup rows and routing option payloads.
 */
final class CategoryRepoParser
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * Initializes the category lookup parser.
     *
     * @param PDO    $db     App database connection used for category lookup reads.
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
     * Finds one category row by slug for public-route resolution.
     *
     * @param string $slug Normalized category slug.
     * @return array<string, mixed>|null Category row, or null when not found.
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
             FROM ' . $this->table('categories') . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrateCategoryRow($row);
    }

    /**
     * Returns lightweight category routing options for mixed routing inventories.
     *
     * @return array<int, array{id: int, name: string, slug: string}> Category routing option rows.
     */
    public function listRoutingOptions(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, slug
             FROM ' . $this->table('categories') . '
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
     * Hydrates one raw category row with taxonomy image URLs.
     *
     * @param array<string, mixed> $row Raw category database row.
     * @return array<string, mixed> Category row with storage-backed image URLs.
     */
    private function hydrateCategoryRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $storage = PreviewConfig::storagePayloadFromRecord('category', $row);
        if (PreviewConfig::supportsFilenameStorage('category')) {
            $row['cover_image'] = $storage['cover_image'] ?? null;
            $row['preview_image'] = $storage['preview_image'] ?? null;
        }

        return array_merge($row, PreviewConfig::pathsFromStoragePayload('category', $id, $storage));
    }
}
