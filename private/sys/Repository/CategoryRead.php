<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/CategoryRead.php
 * Read-only data access for page category records and taxonomy set assignments.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Media\PreviewConfig;

/**
 * SELECT and lookup methods for page categories.
 *
 * Write operations (INSERT, UPDATE, DELETE) live in CategoryWrite.
 * Shared only between the read and write sides — no panel-view logic here.
 */
class CategoryRead
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    /**
     * @param PDO    $db     Active database connection.
     * @param string $driver Database driver string ('mysql', 'sqlite', 'pgsql').
     * @param string $prefix Table name prefix for this Raven installation.
     * @return void
     */
    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '';
    }

    /**
     * Returns all categories with linked page counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $setColumn = $this->setColumn('c');

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, ' . $setColumn . ', c.description, c.created, c.updated,
                    c.cover_image, c.preview_image, c.icon_image,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $categories . ' c
             LEFT JOIN (
                 SELECT category, COUNT(*) AS page_count
                 FROM ' . $pageCategories . '
                 GROUP BY category
             ) pc ON pc.category = c.id
             ORDER BY c.name ASC, c.id ASC'
        );
        // LEFT JOIN keeps categories with zero linked pages visible in admin listings.
        $stmt->execute();

        return $this->hydrateRows($stmt->fetchAll() ?: []);
    }

    /**
     * Returns total category count, optionally filtered by taxonomy set id.
     *
     * @param int|null $setId Optional taxonomy set id filter; null returns unfiltered count.
     * @return int Total matching category count.
     */
    public function count(?int $setId = null): int
    {
        $categories = $this->table('categories');
        $sql = 'SELECT COUNT(*) FROM ' . $categories;
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
     * Returns paginated categories with linked page counts, optionally filtered by taxonomy set.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter; null returns all sets.
     * @return array<int, array<string, mixed>> Hydrated category rows with page counts.
     */
    public function listPaged(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE ' . $this->setColumn('c') . ' = :set_id';
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.slug, ' . $this->setColumn('c') . ', c.description, c.created, c.updated,
                    c.cover_image, c.preview_image, c.icon_image,
                    COALESCE(pc.page_count, 0) AS page_count
             FROM ' . $categories . ' c
             LEFT JOIN (
                 SELECT category, COUNT(*) AS page_count
                 FROM ' . $pageCategories . '
                 GROUP BY category
             ) pc ON pc.category = c.id
             ' . $whereSql . '
             ORDER BY c.name ASC, c.id ASC
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
     * Returns one paginated category page plus total row count in one query.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter; null returns all sets.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        $categories = $this->table('categories');
        $pageCategories = $this->table('page_categories');
        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $whereSql = '';
        if ($setId !== null && $setId >= 0) {
            $whereSql = 'WHERE ' . $this->setColumn('c') . ' = :set_id';
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
                 SELECT c.id, c.name, c.slug, ' . $this->setColumn('c') . ', c.description, c.created, c.updated,
                        c.cover_image, c.preview_image, c.icon_image,
                        COALESCE(pc.page_count, 0) AS page_count
                 FROM ' . $categories . ' c
                 LEFT JOIN (
                     SELECT category, COUNT(*) AS page_count
                     FROM ' . $pageCategories . '
                     GROUP BY category
                 ) pc ON pc.category = c.id
                 ' . $whereSql . '
                 ORDER BY c.name ASC, c.id ASC
                 LIMIT :limit OFFSET :offset
             ) AS page_rows
             CROSS JOIN (
                 SELECT COUNT(*) AS total_rows
                 FROM ' . $categories . ($setId !== null && $setId >= 0 ? ' WHERE ' . $this->setColumn() . ' = :set_id_total' : '') . '
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
     * Returns minimal category options suitable for select controls and parser lookups.
     *
     * @return array<int, array{id: int, name: string, slug: string, set: int}>
     */
    public function listOptions(): array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . '
             FROM ' . $categories . '
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
     * Used by CategoryWrite to filter incoming save payloads before persistence.
     *
     * @param array<int> $ids Candidate id list; non-positive values are ignored.
     * @return array<int> Subset of ids that match rows in the categories table.
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

        $categories = $this->table('categories');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id
             FROM ' . $categories . '
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
     * Returns one category by id.
     *
     * @param int $id Category id to resolve.
     * @return array<string, mixed>|null Hydrated category row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . ', description, created, updated,
                    cover_image, preview_image, icon_image
             FROM ' . $categories . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Returns one category by slug.
     *
     * @param string $slug Category slug to resolve.
     * @return array<string, mixed>|null Hydrated category row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id, name, slug, ' . $this->setColumn() . ', description, created, updated,
                    cover_image, preview_image, icon_image
             FROM ' . $categories . '
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateRow($row);
    }

    /**
     * Returns one category id by slug, or null when not found.
     *
     * @param string $slug Category slug to resolve.
     * @return int|null Category id, or null when no match exists.
     */
    public function idBySlug(string $slug): ?int
    {
        $categories = $this->table('categories');

        $stmt = $this->db->prepare(
            'SELECT id FROM ' . $categories . ' WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Returns a map of category id → set id for a given list of category ids.
     *
     * @param array<int> $ids Category ids to look up.
     * @return array<int, int> Map of category id to its taxonomy set id (0 when unassigned).
     */
    public function setIdsByIds(array $ids): array
    {
        $normalizedIds = $this->existingIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        $categories = $this->table('categories');
        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT id, ' . $this->setColumn() . '
             FROM ' . $categories . '
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
     * Returns category counts grouped by taxonomy set id.
     *
     * @return array<int, int> Map of taxonomy set id to category count.
     */
    public function countsBySetId(): array
    {
        $categories = $this->table('categories');
        $stmt = $this->db->prepare(
            'SELECT ' . $this->setColumn() . ', COUNT(*) AS row_count
             FROM ' . $categories . '
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
     * Hydrates a batch of raw category rows with image path data.
     *
     * @param array<int, array<string, mixed>> $rows Raw PDO category rows.
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
     * Hydrates one raw category row with resolved image paths and normalized set id.
     *
     * @param array<string, mixed> $row Raw PDO category row.
     * @return array<string, mixed> Hydrated row with image URL keys appended.
     */
    private function hydrateRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $storage = PreviewConfig::storagePayloadFromRecord('categories', $row);
        $row['set'] = (int) ($row['set'] ?? 0);
        $row['cover_image'] = $storage['cover_image'] ?? null;
        $row['preview_image'] = $storage['preview_image'] ?? null;
        $row['icon_image'] = $storage['icon_image'] ?? null;

        return array_merge(
            $row,
            PreviewConfig::pathsFromStoragePayload('categories', $id, $storage)
        );
    }

    /**
     * Returns the backend-quoted `set` column reference, optionally prefixed with a table alias.
     *
     * The `set` keyword is reserved in MySQL, requiring backtick quoting on that driver.
     *
     * @param string|null $alias Optional table alias to prepend (e.g. 'c' → 'c.`set`').
     * @return string Quoted column expression ready for embedding in SQL.
     */
    private function setColumn(?string $alias = null): string
    {
        $column = $this->driver === 'mysql' ? '`set`' : '"set"';
        return $alias !== null && $alias !== '' ? ($alias . '.' . $column) : $column;
    }
}
