<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/SetRead.php
 * Read-only data access for filesystem-backed taxonomy set records.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use Raven\Lib\Parser\SetParser;

/**
 * SELECT and lookup methods for taxonomy set records.
 *
 * Write operations (save, delete) live in SetWrite.
 * The in-process record cache lives here; SetWrite calls clearCache() after mutations.
 */
class SetRead
{
    private string $taxonomyType;
    private string $setDirectory;
    private SetParser $setParser;
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
        $this->setDirectory = rtrim($setDirectory, '/');
        $this->setParser = new SetParser($setDirectory, $this->taxonomyType);
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
        // Reuse the in-process snapshot until a write invalidates it.
        if (is_array($this->cache)) {
            return $this->cache;
        }

        SetWrite::ensureRootRecord($this->setDirectory, $this->taxonomyType, $this->rootRecord());

        $rows = [];
        // Load and validate each set file before canonicalizing it into one record row.
        foreach ($this->setParser->listSetFilePaths() as $path) {
            $loaded = $this->setParser->loadRecordFromPath($path);
            // Skip unreadable or malformed files instead of poisoning list output.
            if (!is_array($loaded)) {
                continue;
            }

            $setId = (int) ($loaded['id'] ?? -1);
            $raw = is_array($loaded['raw'] ?? null) ? $loaded['raw'] : [];
            // Ignore files that do not map to valid ids or have empty payload data.
            if ($setId < SetParser::DEFAULT_SET_ID || $raw === []) {
                continue;
            }

            $rows[] = $this->canonicalizeRecord($setId, $raw);
        }

        usort($rows, static function (array $left, array $right): int {
            $leftId = (int) ($left['id'] ?? 0);
            $rightId = (int) ($right['id'] ?? 0);
            // Keep the stock/default set pinned at the top of selector lists.
            if ($leftId === SetParser::DEFAULT_SET_ID || $rightId === SetParser::DEFAULT_SET_ID) {
                return $leftId <=> $rightId;
            }

            $nameCompare = strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            // Name order is primary after default-set pinning.
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
        // Project full records down to minimal option payloads for select controls.
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
        // Linear scan is acceptable because taxonomy set counts remain small.
        foreach ($this->listAll() as $row) {
            // Compare ids after normalization to protect against mixed scalar sources.
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
        $slug = SetParser::normalizeSlug((string) ($raw['slug'] ?? ''));
        $description = trim((string) ($raw['description'] ?? ''));
        $createdAt = trim((string) ($raw['created_at'] ?? ''));

        // Default set values are fixed so they stay stable across filesystem rewrites.
        if ($id === SetParser::DEFAULT_SET_ID) {
            $name = SetParser::defaultSetName($this->taxonomyType);
            $slug = SetParser::DEFAULT_SET_SLUG;
            $description = SetParser::defaultSetDescription($this->taxonomyType);
        } else {
            // Fill missing names from slug/id so every non-default set has a readable label.
            if ($name === '') {
                $name = ucwords(str_replace('-', ' ', $slug !== '' ? $slug : ('set-' . $id)));
            }
            // Slug defaults to normalized name when omitted in stored data.
            if ($slug === '') {
                $slug = SetParser::normalizeSlug($name);
            }
            // Last-resort slug ensures a writable canonical filename for the record.
            if ($slug === '') {
                $slug = 'set-' . $id;
            }
        }

        // Backfill missing timestamps for legacy records that predate created_at.
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
