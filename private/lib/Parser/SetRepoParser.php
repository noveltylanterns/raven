<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/SetRepoParser.php
 * Repository-facing taxonomy-set parser primitives.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

/**
 * Repository-safe taxonomy-set parser façade.
 *
 * Repositories are allowed to depend only on focused `*RepoParser` primitives.
 * This class exposes the set parser policy/read helpers under that contract.
 */
final class SetRepoParser
{
    /** Sentinel set-id meaning "all sets"; never persisted as a real record. */
    public const ALL_SET_ID = SetParser::ALL_SET_ID;

    /** Id assigned to the system-default set that is always present. */
    public const DEFAULT_SET_ID = SetParser::DEFAULT_SET_ID;

    /** Slug reserved for the system-default set. */
    public const DEFAULT_SET_SLUG = SetParser::DEFAULT_SET_SLUG;

    private SetParser $setParser;

    /**
     * Prepares the repo-facing parser for one set directory/taxonomy type pair.
     *
     * @param string $setDirectory Absolute path to the directory containing set PHP files.
     * @param string $taxonomyType Taxonomy type label (e.g. 'category', 'tag').
     * @return void
     */
    public function __construct(string $setDirectory, string $taxonomyType)
    {
        $this->setParser = new SetParser($setDirectory, $taxonomyType);
    }

    /**
     * Normalizes a raw set id value to an integer or null.
     *
     * @param mixed $value Raw value to parse; accepts any scalar or null.
     * @param bool $allowAll When true, the ALL_SET_ID sentinel (0) is allowed.
     * @return int|null Validated set id, or null when absent/invalid.
     */
    public static function normalizeSetId(mixed $value, bool $allowAll = false): ?int
    {
        return SetParser::normalizeSetId($value, $allowAll);
    }

    /**
     * Normalizes a raw slug string to lowercase-alphanumeric-hyphen form.
     *
     * @param string $value Raw slug string.
     * @return string Normalized slug string.
     */
    public static function normalizeSlug(string $value): string
    {
        return SetParser::normalizeSlug($value);
    }

    /**
     * Returns whether one slug meets the minimum set-slug format requirements.
     *
     * @param string $value Slug to test.
     * @return bool True when the slug is valid.
     */
    public static function isValidSlug(string $value): bool
    {
        return SetParser::isValidSlug($value);
    }

    /**
     * Returns the display name for the default taxonomy set of a given type.
     *
     * @param string $taxonomyType Taxonomy type (e.g. 'tag' or 'category').
     * @return string Human-readable default set name.
     */
    public static function defaultSetName(string $taxonomyType): string
    {
        return SetParser::defaultSetName($taxonomyType);
    }

    /**
     * Returns the description for the default taxonomy set of a given type.
     *
     * @param string $taxonomyType Taxonomy type (e.g. 'tag' or 'category').
     * @return string Human-readable default set description.
     */
    public static function defaultSetDescription(string $taxonomyType): string
    {
        return SetParser::defaultSetDescription($taxonomyType);
    }

    /**
     * Returns a sorted list of all set file paths in the store directory.
     *
     * @return array<int, string> Absolute file paths sorted by set id ascending.
     */
    public function listSetFilePaths(): array
    {
        return $this->setParser->listSetFilePaths();
    }

    /**
     * Loads one set record payload from a file path and returns id+raw as a pair.
     *
     * @param string $path Absolute path to the set PHP file.
     * @return array{id: int, raw: array<string, mixed>}|null Record pair, or null when unrecognized.
     */
    public function loadRecordFromPath(string $path): ?array
    {
        return $this->setParser->loadRecordFromPath($path);
    }

    /**
     * Returns the next available set id (one above the current maximum).
     *
     * @return int Next id that does not yet correspond to any stored set file.
     */
    public function nextAvailableId(): int
    {
        return $this->setParser->nextAvailableId();
    }
}

