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

    /**
     * Prepares the set repository for the given taxonomy type and file-backed storage directory.
     *
     * @param string $taxonomyType  Lowercase taxonomy type ('category' or 'tag').
     * @param string $setDirectory  Absolute path to the directory holding set JSON files.
     */
    public function __construct(string $taxonomyType, string $setDirectory)
    {
        $this->taxonomyType = strtolower(trim($taxonomyType));
        $this->fileStore = new SetParser($setDirectory, $this->taxonomyType);
        $this->fileScribe = new SetScribe($setDirectory, $this->taxonomyType);
    }

    /**
     * Returns all taxonomy set records, sorted with the default set first then alphabetically by name.
     *
     * @return array<int, array<string, mixed>> Canonicalized set records.
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
     * Returns minimal set option rows suitable for select controls and parser lookups.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_root: bool}> Set option rows.
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
     * Returns one taxonomy set record by its numeric id.
     *
     * @param int $id Taxonomy set id to resolve.
     * @return array<string, mixed>|null Set record, or null when not found.
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

    /**
     * Returns true when a taxonomy set with the given id exists.
     *
     * @param int $id Taxonomy set id to check.
     * @return bool True when the set exists.
     */
    public function existsId(int $id): bool
    {
        return $this->findById($id) !== null;
    }

    /**
     * Creates or updates one taxonomy set record and returns its id.
     *
     * @param array<string, mixed> $data Set fields; 'name' and a valid 'slug' are required for non-default sets.
     * @return int The saved (or assigned) taxonomy set id.
     * @throws \RuntimeException When required fields are missing or the slug conflicts with another set.
     */
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

    /**
     * Deletes one taxonomy set record by id.
     *
     * @param int $id Taxonomy set id to delete.
     * @throws \RuntimeException When attempting to delete the stock default set.
     */
    public function deleteById(int $id): void
    {
        if ($id === SetParser::DEFAULT_SET_ID) {
            throw new RuntimeException('The stock default set cannot be deleted.');
        }

        $this->fileScribe->deleteById($id);
        $this->cache = null;
    }

    /**
     * Normalizes raw file-record data into a canonical set array, filling in defaults for missing fields.
     *
     * @param int                  $id  Set id from the file path.
     * @param array<string, mixed> $raw Raw key-value data from the set JSON file.
     * @return array<string, mixed> Canonicalized set record.
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
     * Returns the canonical default-set seed record used to bootstrap the root set file.
     *
     * @return array<string, mixed> Default set record with stock id, name, slug, and description.
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
