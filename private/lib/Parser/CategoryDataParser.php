<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/CategoryDataParser.php
 * Read-only category lookup and panel-list parser backed by CategoryRead.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\CategoryRead;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed category read helper.
 *
 * Exposes read-only category lookups used by public routes, panel list/edit flows, and the CLI.
 * Category routing policy (enabled flag, route prefix) lives in CategoryRouteParser.
 */
final class CategoryDataParser
{
    private InputSanitizer $input;
    private ?CategoryRead $categoryRepo;

    /**
     * Initializes the category data reader.
     *
     * @param InputSanitizer          $input        Input normalizer used when validating slugs and ids.
     * @param CategoryRead|null $categoryRepo Optional category repository for read-only category lookups.
     */
    public function __construct(InputSanitizer $input, ?CategoryRead $categoryRepo = null)
    {
        $this->input = $input;
        $this->categoryRepo = $categoryRepo;
    }

    /**
     * Returns all categories for read-only listing flows.
     *
     * @return array<int, array<string, mixed>> Category rows.
     */
    public function listAll(): array
    {
        return $this->categoryRepo()->listAll();
    }

    /**
     * Returns the total category count, optionally filtered by set.
     *
     * @param int|null $setId Optional taxonomy set id filter.
     * @return int             Total matching category count.
     */
    public function count(?int $setId = null): int
    {
        return $this->categoryRepo()->count($this->normalizeSetId($setId));
    }

    /**
     * Returns a flat list of paginated category rows, optionally filtered by set.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter.
     * @return array<int, array<string, mixed>> Category rows.
     */
    public function listPaged(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->categoryRepo()->listPaged(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * Returns one paginated page of category rows plus total count.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->categoryRepo()->listPage(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * Returns all categories as lightweight option rows for select lists.
     *
     * @return array<int, array{id: int, name: string, slug: string, set: int}> Option rows.
     */
    public function listOptions(): array
    {
        return $this->categoryRepo()->listOptions();
    }

    /**
     * Filters a list of category ids to only those that actually exist in the database.
     *
     * @param array<int, mixed> $ids Raw category ids to check.
     * @return array<int>            Confirmed existing category ids.
     */
    public function existingIds(array $ids): array
    {
        $normalizedIds = $this->normalizeIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        return $this->categoryRepo()->existingIds($normalizedIds);
    }

    /**
     * Returns one category row by numeric id.
     *
     * @param int $id Category id to resolve.
     * @return array<string, mixed>|null Category row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->categoryRepo()->findById($id);
    }

    /**
     * Returns one category row by slug.
     *
     * @param string $slug Category slug to resolve.
     * @return array<string, mixed>|null Category row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->categoryRepo()->findBySlug($normalizedSlug);
    }

    /**
     * Returns the numeric id for a category by slug.
     *
     * @param string $slug Category slug to resolve.
     * @return int|null    Category id, or null when not found.
     */
    public function idBySlug(string $slug): ?int
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->categoryRepo()->idBySlug($normalizedSlug);
    }

    /**
     * Returns the taxonomy set id for each of the given category ids.
     *
     * @param array<int, mixed> $ids Category ids to query.
     * @return array<int, int>       Map of category id to set id.
     */
    public function setIdsByIds(array $ids): array
    {
        $normalizedIds = $this->normalizeIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        return $this->categoryRepo()->setIdsByIds($normalizedIds);
    }

    /**
     * Returns the category count for each taxonomy set id.
     *
     * @return array<int, int> Map of set id to category count.
     */
    public function countsBySetId(): array
    {
        return $this->categoryRepo()->countsBySetId();
    }

    /**
     * Returns the injected category repository for repo-backed reads.
     *
     * @return CategoryRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function categoryRepo(): CategoryRead
    {
        if (!$this->categoryRepo instanceof CategoryRead) {
            throw new RuntimeException('CategoryDataParser requires a CategoryRead for repository-backed reads.');
        }

        return $this->categoryRepo;
    }

    /**
     * Normalizes an array of raw id values to a deduplicated list of positive integers.
     *
     * @param array<int, mixed> $ids Raw id values.
     * @return array<int>            Normalized positive integer ids.
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = $this->input->int($id, 1);
            if ($value === null) {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * Normalizes a set id for use in repository queries.
     *
     * @param int|null $setId Raw set id value.
     * @return int|null       Non-negative set id, or null when invalid.
     */
    private function normalizeSetId(?int $setId): ?int
    {
        return is_int($setId) && $setId >= 0 ? $setId : null;
    }
}
