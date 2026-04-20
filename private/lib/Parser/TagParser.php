<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/TagParser.php
 * Read-only tag lookup and panel-list parser backed by TagRepository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\TagRepository;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed tag read helper with shared input normalization.
 */
final class TagParser
{
    private InputSanitizer $input;
    private ?TagRepository $tagRepo;

    /**
     * @param InputSanitizer   $input
     * @param TagRepository|null $tagRepo
     */
    public function __construct(InputSanitizer $input, ?TagRepository $tagRepo = null)
    {
        $this->input = $input;
        $this->tagRepo = $tagRepo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->tagRepo()->listAll();
    }

    public function countForPanel(?int $setId = null): int
    {
        return $this->tagRepo()->countForPanel($this->normalizeSetId($setId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->tagRepo()->listForPanel(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0, ?int $setId = null): array
    {
        return $this->tagRepo()->listPageForPanel(max(1, $limit), max(0, $offset), $this->normalizeSetId($setId));
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, set: int}>
     */
    public function listOptions(): array
    {
        return $this->tagRepo()->listOptions();
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

        return $this->tagRepo()->existingIds($normalizedIds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->tagRepo()->findById($id);
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

        return $this->tagRepo()->findBySlug($normalizedSlug);
    }

    public function idBySlug(string $slug): ?int
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->tagRepo()->idBySlug($normalizedSlug);
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

        return $this->tagRepo()->setIdsByIds($normalizedIds);
    }

    /**
     * @return array<int, int>
     */
    public function countsBySetId(): array
    {
        return $this->tagRepo()->countsBySetId();
    }

    private function tagRepo(): TagRepository
    {
        if (!$this->tagRepo instanceof TagRepository) {
            throw new RuntimeException('TagParser requires a TagRepository for repository-backed reads.');
        }

        return $this->tagRepo;
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
