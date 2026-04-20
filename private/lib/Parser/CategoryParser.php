<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/CategoryParser.php
 * Read-only category lookup and panel-list parser backed by CategoryRepository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\CategoryRepository;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed category read helper with shared input normalization.
 */
final class CategoryParser
{
    private InputSanitizer $input;
    private ?CategoryRepository $categoryRepo;

    /**
     * @param InputSanitizer         $input
     * @param CategoryRepository|null $categoryRepo
     */
    public function __construct(InputSanitizer $input, ?CategoryRepository $categoryRepo = null)
    {
        $this->input = $input;
        $this->categoryRepo = $categoryRepo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->categoryRepo()->listAll();
    }

    public function countForPanel(?int $setId = null): int
    {
        return $this->categoryRepo()->countForPanel($this->normalizeSetId($setId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->categoryRepo()->listForPanel(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->categoryRepo()->listPageForPanel(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, set: int}>
     */
    public function listOptions(): array
    {
        return $this->categoryRepo()->listOptions();
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int>
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
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->categoryRepo()->findById($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->categoryRepo()->findBySlug($normalizedSlug);
    }

    public function idBySlug(string $slug): ?int
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->categoryRepo()->idBySlug($normalizedSlug);
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
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
     * @return array<int, int>
     */
    public function countsBySetId(): array
    {
        return $this->categoryRepo()->countsBySetId();
    }

    private function categoryRepo(): CategoryRepository
    {
        if (!$this->categoryRepo instanceof CategoryRepository) {
            throw new RuntimeException('CategoryParser requires a CategoryRepository for repository-backed reads.');
        }

        return $this->categoryRepo;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int>
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

    private function normalizeSetId(?int $setId): ?int
    {
        return is_int($setId) && $setId >= 0 ? $setId : null;
    }
}
