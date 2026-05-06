<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/TagRead.php
 * Read-only data access for page tag records and taxonomy set assignments.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Media\PreviewConfig;

/**
 * SELECT and lookup methods for page tags.
 *
 * Write operations (INSERT, UPDATE, DELETE) live in TagWrite.
 * Shared only between the read and write sides — no panel-view logic here.
 */
class TagRead
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns all tags with linked page counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $setColumn = $this->setColumn('t');

        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.slug, ' . $setColumn . ', t.description, t.created, t.updated,
                    t.cover_image, t.preview_image, t.icon_image,
                    COALESCE(pt.page_count, 0) AS page_count
             FROM ' . $tags . ' t
             LEFT JOIN (
                 SELECT tag, COUNT(*) AS page_count
                 FROM ' . $pageTags . '
                 GROUP BY tag
             ) pt ON pt.tag = t.id
             ORDER BY t.name ASC, t.id ASC'
        );
        // LEFT JOIN keeps tags with zero linked pages visible in admin listings.
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: []);
    }

    /**
     * Returns total tag count, optionally filtered by taxonomy set id.
     *
     * @param int|null $setId Optional taxonomy set id filter; null returns unfiltered count.
     * @return int Total matching tag count.
     */
    public function count(?int $setId = null): int
    {
        $tags = $this->table('tags');
        $sql = 'SELECT COUNT(*) FROM ' . $tags;
        $params = [];
        if ($setId !== null && $setId >= 0) {
            $sql .= ' WHERE ' . $this->setColumn() . ' = :set_id';
            $params[':set_id'] = $setId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns paginated tags with linked page counts, optionally filtered by taxonomy set.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter; null returns all sets.
     * @return array<int, array<string, mixed>> Hydrated tag rows with page counts.
     */
    public function listPaged(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE ' . $this->setColumn('t') . ' = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.slug, ' . $this->setColumn('t') . ', t.description, t.created, t.updated,
                    t.cover_image, t.preview_image, t.icon_image,
                    COALESCE(pt.page_count, 0) AS page_count
             FROM ' . $tags . ' t
             LEFT JOIN (
                 SELECT tag, COUNT(*) AS page_count
                 FROM ' . $pageTags . '
                 GROUP BY tag
             ) pt ON pt.tag = t.id
             ' . $whereSql . '
             ORDER BY t.name ASC, t.id ASC
             LIMIT :limit OFFSET :offset'
        );
        if ($setId !== null && $setId >= 0) {
            $stmt->bindValue(':set_id', $setId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: []);
    }

    /**
     * Returns one paginated tag page plus total row count in one query.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter; null returns all sets.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $tags = $this->table('tags');
        $pageTags = $this->table('page_tags');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE ' . $this->setColumn('t') . ' = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT page_rows.id,
                    page_rows.name,
                    page_rows.slug,
                    ' . $this->setColumn('page_rows') . ',
                    page_rows.description,
                    page_rows.created,
                    page_rows.updated,
                    page_rows.cover_image,
                    page_rows.preview_image,
                    page_rows.icon_image,
                    page_rows.page_count,
                    totals.total_rows
             FROM (
                 SELECT t.id, t.name, t.slug, ' . $this->setColumn('t') . ', t.description, t.created, t.updated,
                        t.cover_image, t.preview_image, t.icon_image,
                        COALESCE(pt.page_count, 0) AS page_count
                 FROM ' . $tags . ' t
                 LEFT JOIN (
                     SELECT tag, COUNT(*) AS page_count
                     FROM ' . $pageTags . '
                     GROUP BY tag
                 ) pt ON pt.tag = t.id
                 ' . $whereSql . '
                 ORDER BY t.name ASC, t.id ASC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $tags . ($setId !== null && $setId >= 0 ? ' WHERE ' . $this->setColumn() . ' = :set_id_total' : '') . '
             ) AS totals'
        );
        if ($setId !== null && $setId >= 0) {
            $stmt->bindValue(':set_id', $setId, PDO::PARAM_INT);
            $stmt->bindValue(':set_id_total', $setId, PDO::PARAM_INT);
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
            $resultRows[] = $this->hydrateRow($row);
        }

        // Offset can target an empty page while rows still exist; recover accurate total.
        if ($resultRows === [] && $safeOffset > 0) {
            $total = $this->count($setId);
        }

        return [
            'rows' => $resultRows,
            'total' => $total,
        ];
    }

    /**
     * Returns minimal tag options suitable for select controls and parser lookups.
     *
     * @return array<int, array{id: int, name: string, slug: string, set: int}>
     */
    public function listOptions(): array
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . '
             FROM ' . $tags . '
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'set' => (int) ($row['set'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Returns only ids that currently exist in storage.
     *
     * Used by TagWrite to filter incoming save payloads before delegating to TagScribe.
     *
     * @param array<int> $ids Candidate id list; non-positive values are ignored.
     * @return array<int> Subset of ids that match rows in the tags table.
     */
    public function existingIds(array $ids): array
    {
        $normalizedIds = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalizedIds[$value] = $value;
            }
        }

        if ($normalizedIds === []) {
            return [];
        }

        $tags = $this->table('tags');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $tags . '
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($normalizedIds));

        $rows = $stmt->fetchAll() ?: [];
        $existing = [];
        foreach ($rows as $row) {
            $value = (int) ($row['id'] ?? 0);
            if ($value > 0) {
                $existing[$value] = $value;
            }
        }

        return array_values($existing);
    }

    /**
     * Returns one tag by id.
     *
     * @param int $id Tag id to resolve.
     * @return array<string, mixed>|null Hydrated tag row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . ', description, created, updated,
                    cover_image, preview_image, icon_image
             FROM ' . $tags . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Returns one tag by slug.
     *
     * @param string $slug Tag slug to resolve.
     * @return array<string, mixed>|null Hydrated tag row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . ', description, created, updated,
                    cover_image, preview_image, icon_image
             FROM ' . $tags . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Returns one tag id by slug, or null when not found.
     *
     * @param string $slug Tag slug to resolve.
     * @return int|null Tag id, or null when no match exists.
     */
    public function idBySlug(string $slug): ?int
    {
        $tags = $this->table('tags');

        $stmt = $this->db->prepare(
            'SELECT id FROM ' . $tags . ' WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Returns a map of tag id → set id for a given list of tag ids.
     *
     * @param array<int> $ids Tag ids to look up.
     * @return array<int, int> Map of tag id to its taxonomy set id (0 when unassigned).
     */
    public function setIdsByIds(array $ids): array
    {
        $normalizedIds = $this->existingIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        $tags = $this->table('tags');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id, ' . $this->setColumn() . '
             FROM ' . $tags . '
             WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($normalizedIds));

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) ($row['id'] ?? 0)] = (int) ($row['set'] ?? 0);
        }

        return $result;
    }

    /**
     * Returns tag counts grouped by taxonomy set id.
     *
     * @return array<int, int> Map of taxonomy set id to tag count.
     */
    public function countsBySetId(): array
    {
        $tags = $this->table('tags');
        $stmt = $this->db->prepare(
            'SELECT ' . $this->setColumn() . ', COUNT(*) AS row_count
             FROM ' . $tags . '
             GROUP BY ' . $this->setColumn()
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(int) ($row['set'] ?? 0)] = (int) ($row['row_count'] ?? 0);
        }

        return $result;
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        return SqlTable::appTable($this->driver, $this->prefix, $table);
    }

    /**
     * Hydrates a batch of raw tag rows with image path data.
     *
     * @param array<int, array<string, mixed>> $rows Raw PDO tag rows.
     * @return array<int, array<string, mixed>> Hydrated rows with resolved image paths.
     */
    private function hydrateRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrateRow($row);
        }

        return $result;
    }

    /**
     * Hydrates one raw tag row with resolved image paths and normalized set id.
     *
     * @param array<string, mixed> $row Raw PDO tag row.
     * @return array<string, mixed> Hydrated row with image URL keys appended.
     */
    private function hydrateRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $storage = PreviewConfig::storagePayloadFromRecord('tags', $row);
        $row['set'] = (int) ($row['set'] ?? 0);
        $row['cover_image'] = $storage['cover_image'] ?? null;
        $row['preview_image'] = $storage['preview_image'] ?? null;
        $row['icon_image'] = $storage['icon_image'] ?? null;

        return array_merge(
            $row,
            PreviewConfig::pathsFromStoragePayload('tags', $id, $storage)
        );
    }

    /**
     * Returns the backend-quoted `set` column reference, optionally prefixed with a table alias.
     *
     * The `set` keyword is reserved in MySQL, requiring backtick quoting on that driver.
     *
     * @param string|null $alias Optional table alias to prepend (e.g. 't' → 't.`set`').
     * @return string Quoted column expression ready for embedding in SQL.
     */
    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }
}
