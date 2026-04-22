<?php

/**
 * RAVEN CMS
 * ~/private/sys/Repository/SetRepository.php
 * Filesystem-backed category/tag taxonomy set repository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use Raven\Lib\Parser\SetParser;
use Raven\Lib\Scribe\SetScribe;
use RuntimeException;

/**
 * Filesystem-backed repository for category/tag taxonomy sets.
 */
final class SetRepository
{
    private string $taxonomyType;
    private SetParser $fileStore;
    private SetScribe $fileScribe;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(string $taxonomyType, string $setDirectory)
    {
        $this->taxonomyType = strtolower(trim($taxonomyType));
        $this->fileStore = new SetParser($setDirectory, $this->taxonomyType);
        $this->fileScribe = new SetScribe($setDirectory, $this->taxonomyType);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        if (is_array($this->cache)) {
            return $this->cache;
        }

        $this->fileScribe->ensureRootRecord($this->rootRecord());

        $rows = [];
        foreach ($this->fileStore->listSetFilePaths() as $path) {
            $loaded = $this->fileStore->loadRecordFromPath($path);
            if (!is_array($loaded)) {
                continue;
            }

            $setId = (int) ($loaded['id'] ?? -1);
            $raw = is_array($loaded['raw'] ?? null) ? $loaded['raw'] : [];
            if ($setId < SetParser::DEFAULT_SET_ID || $raw === []) {
                continue;
            }

            $rows[] = $this->canonicalizeRecord($setId, $raw);
        }

        usort($rows, static function (array $left, array $right): int {
            $leftId = (int) ($left['id'] ?? 0);
            $rightId = (int) ($right['id'] ?? 0);
            if ($leftId === SetParser::DEFAULT_SET_ID || $rightId === SetParser::DEFAULT_SET_ID) {
                return $leftId <=> $rightId;
            }

            $nameCompare = strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return $leftId <=> $rightId;
        });

        $this->cache = $rows;
        return $rows;
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, is_root: bool}>
     */
    public function listOptions(): array
    {
        $result = [];
        foreach ($this->listAll() as $row) {
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'is_root' => (int) ($row['id'] ?? -1) === SetParser::DEFAULT_SET_ID,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        foreach ($this->listAll() as $row) {
            if ((int) ($row['id'] ?? -1) === $id) {
                return $row;
            }
        }

        return null;
    }

    public function existsId(int $id): bool
    {
        return $this->findById($id) !== null;
    }

    public function save(array $data): int
    {
        $providedId = SetParser::normalizeSetId($data['id'] ?? null);
        $setId = $providedId ?? $this->fileStore->nextAvailableId();
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $slug = SetParser::normalizeSlug((string) ($data['slug'] ?? ''));

        if ($setId === SetParser::DEFAULT_SET_ID) {
            $name = SetParser::defaultSetName($this->taxonomyType);
            $slug = SetParser::DEFAULT_SET_SLUG;
            $description = SetParser::defaultSetDescription($this->taxonomyType);
        }

        if ($name === '' || !SetParser::isValidSlug($slug)) {
            throw new RuntimeException('Set name and valid slug are required.');
        }

        foreach ($this->listAll() as $existing) {
            $existingId = (int) ($existing['id'] ?? -1);
            if ($existingId === $setId) {
                continue;
            }

            if (strtolower(trim((string) ($existing['slug'] ?? ''))) === $slug) {
                throw new RuntimeException('A ' . $this->taxonomyType . ' set with that slug already exists.');
            }
        }

        $existing = $this->findById($setId);
        $createdAt = trim((string) ($existing['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        $record = [
            'id' => $setId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_stock' => $setId === SetParser::DEFAULT_SET_ID,
            'created_at' => $createdAt,
        ];

        $this->fileScribe->writeRecordById($setId, $record);
        $this->cache = null;
        return $setId;
    }

    public function deleteById(int $id): void
    {
        if ($id === SetParser::DEFAULT_SET_ID) {
            throw new RuntimeException('The stock default set cannot be deleted.');
        }

        $this->fileScribe->deleteById($id);
        $this->cache = null;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function canonicalizeRecord(int $id, array $raw): array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        $slug = SetParser::normalizeSlug((string) ($raw['slug'] ?? ''));
        $description = trim((string) ($raw['description'] ?? ''));
        $createdAt = trim((string) ($raw['created_at'] ?? ''));

        if ($id === SetParser::DEFAULT_SET_ID) {
            $name = SetParser::defaultSetName($this->taxonomyType);
            $slug = SetParser::DEFAULT_SET_SLUG;
            $description = SetParser::defaultSetDescription($this->taxonomyType);
        } else {
            if ($name === '') {
                $name = ucwords(str_replace('-', ' ', $slug !== '' ? $slug : ('set-' . $id)));
            }
            if ($slug === '') {
                $slug = SetParser::normalizeSlug($name);
            }
            if ($slug === '') {
                $slug = 'set-' . $id;
            }
        }

        if ($createdAt === '') {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_stock' => $id === SetParser::DEFAULT_SET_ID,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rootRecord(): array
    {
        return [
            'id' => SetParser::DEFAULT_SET_ID,
            'name' => SetParser::defaultSetName($this->taxonomyType),
            'slug' => SetParser::DEFAULT_SET_SLUG,
            'description' => SetParser::defaultSetDescription($this->taxonomyType),
            'is_stock' => true,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
