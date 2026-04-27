<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/TagDataParser.php
 * Read-only tag lookup and panel-list parser backed by TagRead.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\TagRead;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed tag read helper.
 *
 * Exposes read-only tag lookups used by public routes, panel list/edit flows, and the CLI.
 * Tag routing policy (enabled flag, route prefix) lives in TagRouteParser.
 */
final class TagDataParser
{
    private InputSanitizer $input;
    private ?TagRead $tagRepo;

    /**
     * Initializes the tag data reader.
     *
     * @param InputSanitizer     $input   Input normalizer used when validating slugs and ids.
     * @param TagRead|null $tagRepo Optional tag repository for read-only tag lookups.
     */
    public function __construct(InputSanitizer $input, ?TagRead $tagRepo = null)
    {
        $this->input = $input;
        $this->tagRepo = $tagRepo;
    }

    /**
     * Returns all tags for read-only listing flows.
     *
     * @return array<int, array<string, mixed>> Tag rows.
     */
    public function listAll(): array
    {
        return $this->tagRepo()->listAll();
    }

    /**
     * Returns the total number of tags visible in the panel list, optionally filtered by set.
     *
     * @param int|null $setId Optional taxonomy set id filter.
     * @return int             Total matching tag count.
     */
    public function countForPanel(?int $setId = null): int
    {
        return $this->tagRepo()->countForPanel($this->normalizeSetId($setId));
    }

    /**
     * Returns a flat list of panel tag rows, optionally filtered by set.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter.
     * @return array<int, array<string, mixed>> Tag rows.
     */
    public function listForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->tagRepo()->listForPanel(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * Returns one paginated page of panel tag rows plus total count.
     *
     * @param int      $limit  Maximum number of rows to return.
     * @param int      $offset Zero-based row offset for pagination.
     * @param int|null $setId  Optional taxonomy set id filter.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->tagRepo()->listPageForPanel(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * Returns all tags as lightweight option rows for select lists.
     *
     * @return array<int, array{id: int, name: string, slug: string, set: int}> Option rows.
     */
    public function listOptions(): array
    {
        return $this->tagRepo()->listOptions();
    }

    /**
     * Filters a list of tag ids to only those that actually exist in the database.
     *
     * @param array<int, mixed> $ids Raw tag ids to check.
     * @return array<int>            Confirmed existing tag ids.
     */
    public function existingIds(array $ids): array
    {
        $normalizedIds = $this->normalizeIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        return $this->tagRepo()->existingIds($normalizedIds);
    }

    /**
     * Returns one tag row by numeric id.
     *
     * @param int $id Tag id to resolve.
     * @return array<string, mixed>|null Tag row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->tagRepo()->findById($id);
    }

    /**
     * Returns one tag row by slug.
     *
     * @param string $slug Tag slug to resolve.
     * @return array<string, mixed>|null Tag row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->tagRepo()->findBySlug($normalizedSlug);
    }

    /**
     * Returns the numeric id for a tag by slug.
     *
     * @param string $slug Tag slug to resolve.
     * @return int|null    Tag id, or null when not found.
     */
    public function idBySlug(string $slug): ?int
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->tagRepo()->idBySlug($normalizedSlug);
    }

    /**
     * Returns the taxonomy set id for each of the given tag ids.
     *
     * @param array<int, mixed> $ids Tag ids to query.
     * @return array<int, int>       Map of tag id to set id.
     */
    public function setIdsByIds(array $ids): array
    {
        $normalizedIds = $this->normalizeIds($ids);
        if ($normalizedIds === []) {
            return [];
        }

        return $this->tagRepo()->setIdsByIds($normalizedIds);
    }

    /**
     * Returns the tag count for each taxonomy set id.
     *
     * @return array<int, int> Map of set id to tag count.
     */
    public function countsBySetId(): array
    {
        return $this->tagRepo()->countsBySetId();
    }

    /**
     * Returns the injected tag repository for repo-backed reads.
     *
     * @return TagRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function tagRepo(): TagRead
    {
        if (!$this->tagRepo instanceof TagRead) {
            throw new RuntimeException('TagDataParser requires a TagRead for repository-backed reads.');
        }

        return $this->tagRepo;
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
