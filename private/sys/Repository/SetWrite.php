<?php
/**
 * RAVEN CMS
 * ~/private/sys/Repository/SetWrite.php
 * Write-side data access for filesystem-backed taxonomy set records.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Repository;

use Raven\Lib\Parser\SetParser;
use Raven\Lib\Scribe\SetScribe;
use RuntimeException;

/**
 * Save and delete methods for taxonomy set records.
 *
 * Read operations (listAll, findById, etc.) live in SetRead, which is injected here
 * so that slug-uniqueness checks during save can iterate the existing record list
 * without duplicating the load-and-cache logic.
 */
final class SetWrite
{
    private SetRead $read;
    private string $taxonomyType;
    private SetParser $setParser;
    private SetScribe $fileScribe;

    /**
     * @param string  $taxonomyType Lowercase taxonomy type ('category' or 'tag').
     * @param string  $setDirectory Absolute path to the directory holding set JSON files.
     * @param SetRead $read         Read-side instance for slug-uniqueness validation during save.
     * @return void
     */
    public function __construct(string $taxonomyType, string $setDirectory, SetRead $read)
    {
        $this->read = $read;
        $this->taxonomyType = strtolower(trim($taxonomyType));
        $this->setParser = new SetParser($setDirectory, $this->taxonomyType);
        $this->fileScribe = new SetScribe($setDirectory, $this->taxonomyType);
    }

    /**
     * Creates or updates one taxonomy set record and returns its id.
     *
     * Iterates the current record list via SetRead to check slug uniqueness.
     * Calls clearCache() on the read side after writing so subsequent reads
     * reflect the new state.
     *
     * @param array<string, mixed> $data Set fields; 'name' and a valid 'slug' are required for non-default sets.
     * @return int The saved (or assigned) taxonomy set id.
     * @throws RuntimeException When required fields are missing or the slug conflicts with another set.
     */
    public function save(array $data): int
    {
        $providedId = SetParser::normalizeSetId($data['id'] ?? null);
        $setId = $providedId ?? $this->setParser->nextAvailableId();
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

        foreach ($this->read->listAll() as $existing) {
            $existingId = (int) ($existing['id'] ?? -1);
            if ($existingId === $setId) {
                continue;
            }

            if (strtolower(trim((string) ($existing['slug'] ?? ''))) === $slug) {
                throw new RuntimeException('A ' . $this->taxonomyType . ' set with that slug already exists.');
            }
        }

        $existing = $this->read->findById($setId);
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
        $this->read->clearCache();
        return $setId;
    }

    /**
     * Deletes one taxonomy set record by id.
     *
     * @param int $id Taxonomy set id to delete.
     * @throws RuntimeException When attempting to delete the stock default set.
     * @return void
     */
    public function deleteById(int $id): void
    {
        if ($id === SetParser::DEFAULT_SET_ID) {
            throw new RuntimeException('The stock default set cannot be deleted.');
        }

        $this->fileScribe->deleteById($id);
        $this->read->clearCache();
    }
}
