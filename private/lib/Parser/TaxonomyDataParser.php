<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/TaxonomyDataParser.php
 * Extension-author read wrapper around PageRepository for taxonomy page-list queries.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\PageRepository;

/**
 * Extension-author wrapper for taxonomy-filtered page-list queries.
 *
 * All SQL lives in `Raven\Core\Repository\PageRepository`. This class is a thin delegation
 * layer so extension authors and brace-tag handlers can query taxonomy page lists through
 * the parser library surface without depending on a repository class directly.
 */
final class TaxonomyDataParser
{
    private PageRepository $pageRepo;

    /**
     * @param PageRepository $pageRepo Canonical page repository for all taxonomy page-list reads.
     */
    public function __construct(PageRepository $pageRepo)
    {
        $this->pageRepo = $pageRepo;
    }

    /**
     * Lists published pages linked to one category slug.
     *
     * @param string $slug   Normalized category slug.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByCategorySlug(string $slug, int $limit, int $offset): array
    {
        return $this->pageRepo->listByCategorySlug($slug, $limit, $offset);
    }

    /**
     * Counts published pages linked to one category slug.
     *
     * @param string $slug Normalized category slug.
     * @return int Published page count for the category.
     */
    public function countPagesByCategorySlug(string $slug): int
    {
        return $this->pageRepo->countByCategorySlug($slug);
    }

    /**
     * Lists published pages linked to one category id.
     *
     * @param int $categoryId Category id to query.
     * @param int $limit      Maximum rows to return.
     * @param int $offset     Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByCategoryId(int $categoryId, int $limit, int $offset): array
    {
        return $this->pageRepo->listByCategoryId($categoryId, $limit, $offset);
    }

    /**
     * Counts published pages linked to one category id.
     *
     * @param int $categoryId Category id to query.
     * @return int Published page count for the category.
     */
    public function countPagesByCategoryId(int $categoryId): int
    {
        return $this->pageRepo->countByCategoryId($categoryId);
    }

    /**
     * Returns one paginated page of published rows linked to one category slug.
     *
     * @param string $slug   Normalized category slug.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByCategorySlug(string $slug, int $limit, int $offset): array
    {
        return $this->pageRepo->listPageByCategorySlug($slug, $limit, $offset);
    }

    /**
     * Returns one paginated page of published rows linked to one category id.
     *
     * @param int $categoryId Category id to query.
     * @param int $limit      Maximum rows to return.
     * @param int $offset     Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByCategoryId(int $categoryId, int $limit, int $offset): array
    {
        return $this->pageRepo->listPageByCategoryId($categoryId, $limit, $offset);
    }

    /**
     * Lists published pages linked to one tag slug.
     *
     * @param string $slug   Normalized tag slug.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByTagSlug(string $slug, int $limit, int $offset): array
    {
        return $this->pageRepo->listByTagSlug($slug, $limit, $offset);
    }

    /**
     * Counts published pages linked to one tag slug.
     *
     * @param string $slug Normalized tag slug.
     * @return int Published page count for the tag.
     */
    public function countPagesByTagSlug(string $slug): int
    {
        return $this->pageRepo->countByTagSlug($slug);
    }

    /**
     * Lists published pages linked to one tag id.
     *
     * @param int $tagId  Tag id to query.
     * @param int $limit  Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @return array<int, array<string, mixed>> Hydrated published page rows.
     */
    public function listPagesByTagId(int $tagId, int $limit, int $offset): array
    {
        return $this->pageRepo->listByTagId($tagId, $limit, $offset);
    }

    /**
     * Counts published pages linked to one tag id.
     *
     * @param int $tagId Tag id to query.
     * @return int Published page count for the tag.
     */
    public function countPagesByTagId(int $tagId): int
    {
        return $this->pageRepo->countByTagId($tagId);
    }

    /**
     * Returns one paginated page of published rows linked to one tag slug.
     *
     * @param string $slug   Normalized tag slug.
     * @param int    $limit  Maximum rows to return.
     * @param int    $offset Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByTagSlug(string $slug, int $limit, int $offset): array
    {
        return $this->pageRepo->listPageByTagSlug($slug, $limit, $offset);
    }

    /**
     * Returns one paginated page of published rows linked to one tag id.
     *
     * @param int $tagId  Tag id to query.
     * @param int $limit  Maximum rows to return.
     * @param int $offset Zero-based row offset.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByTagId(int $tagId, int $limit, int $offset): array
    {
        return $this->pageRepo->listPageByTagId($tagId, $limit, $offset);
    }
}
