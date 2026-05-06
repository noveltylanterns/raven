<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/SetRead.php
 * Read-only data access for filesystem-backed taxonomy set records.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use Raven\Lib\Parser\SetRepoParser;
use Raven\Lib\Scribe\SetScribe;

/**
 * SELECT and lookup methods for taxonomy set records.
 *
 * Write operations (save, delete) live in SetWrite.
 * The in-process record cache lives here; SetWrite calls clearCache() after mutations.
 */
class SetRead
{
    private string $taxonomyType;
    private SetRepoParser $setRepoParser;
    private SetScribe $fileScribe;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * @param string $taxonomyType Lowercase taxonomy type ('category' or 'tag').
     * @param string $setDirectory Absolute path to the directory holding set JSON files.
     * @return void
     */
    public function __construct(string $taxonomyType, string $setDirectory)
    {
        $this->taxonomyType = strtolower(trim($taxonomyType));
        $this->setRepoParser = new SetRepoParser($setDirectory, $this->taxonomyType);
        $this->fileScribe = new SetScribe($setDirectory, $this->taxonomyType);
    }

    /**
     * Returns all taxonomy set records, sorted with the default set first then alphabetically by name.
     *
     * Maintains an in-process cache; call clearCache() after any write to invalidate it.
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
        foreach ($this->setRepoParser->listSetFilePaths() as $path) {
            $loaded = $this->setRepoParser->loadRecordFromPath($path);
            if (!is_array($loaded)) {
                continue;
            }

            $setId = (int) ($loaded['id'] ?? -1);
            $raw = is_array($loaded['raw'] ?? null) ? $loaded['raw'] : [];
            if ($setId < SetRepoParser::DEFAULT_SET_ID || $raw === []) {
                continue;
            }

            $rows[] = $this->canonicalizeRecord($setId, $raw);
        }

        usort($rows, static function (array $left, array $right): int {
            $leftId = (int) ($left['id'] ?? 0);
            $rightId = (int) ($right['id'] ?? 0);
            if ($leftId === SetRepoParser::DEFAULT_SET_ID || $rightId === SetRepoParser::DEFAULT_SET_ID) {
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
                'is_root' => (int) ($row['id'] ?? -1) === SetRepoParser::DEFAULT_SET_ID,
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
     * Clears the in-process record cache.
     *
     * Must be called by SetWrite after any mutation so subsequent reads
     * reflect the new state from disk.
     * @return void
     */
    public function clearCache(): void
    {
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
        $slug = SetRepoParser::normalizeSlug((string) ($raw['slug'] ?? ''));
        $description = trim((string) ($raw['description'] ?? ''));
        $createdAt = trim((string) ($raw['created_at'] ?? ''));

        if ($id === SetRepoParser::DEFAULT_SET_ID) {
            $name = SetRepoParser::defaultSetName($this->taxonomyType);
            $slug = SetRepoParser::DEFAULT_SET_SLUG;
            $description = SetRepoParser::defaultSetDescription($this->taxonomyType);
        } else {
            if ($name === '') {
                $name = ucwords(str_replace('-', ' ', $slug !== '' ? $slug : ('set-' . $id)));
            }
            if ($slug === '') {
                $slug = SetRepoParser::normalizeSlug($name);
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
            'is_stock' => $id === SetRepoParser::DEFAULT_SET_ID,
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
            'id' => SetRepoParser::DEFAULT_SET_ID,
            'name' => SetRepoParser::defaultSetName($this->taxonomyType),
            'slug' => SetRepoParser::DEFAULT_SET_SLUG,
            'description' => SetRepoParser::defaultSetDescription($this->taxonomyType),
            'is_stock' => true,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
