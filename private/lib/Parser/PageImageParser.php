<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/PageImageParser.php
 * Read-only page-image lookup helper backed by PageImageRead.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\PageImageRead;
use RuntimeException;

/**
 * Repository-backed page-image read helper.
 *
 * Exposes read-only page-image queries for public rendering, panel preview,
 * and extension access. Write operations (insert, update gallery, delete)
 * live on PageImageScribe, which the repository already delegates to internally.
 */
final class PageImageParser
{
    private ?PageImageRead $pageImageRepo;

    /**
     * Prepares the page-image parser for read-only image lookups.
     *
     * @param PageImageRead|null $pageImageRepo Optional repository for read-only page-image queries.
     */
    public function __construct(?PageImageRead $pageImageRepo = null)
    {
        $this->pageImageRepo = $pageImageRepo;
    }

    /**
     * Returns true when a page row exists for the given id.
     *
     * @param int $pageId Page id to check.
     * @return bool True when the page exists, false otherwise.
     */
    public function pageExists(int $pageId): bool
    {
        if ($pageId < 1) {
            return false;
        }

        return $this->repo()->pageExists($pageId);
    }

    /**
     * Returns true when the gallery feature is enabled for a page.
     *
     * @param int $pageId Page id to check.
     * @return bool True when gallery is enabled for the page.
     */
    public function isGalleryEnabledForPage(int $pageId): bool
    {
        if ($pageId < 1) {
            return false;
        }

        return $this->repo()->isGalleryEnabledForPage($pageId);
    }

    /**
     * Returns all images and variants for one page in panel-edit sort order.
     *
     * @param int $pageId Page id whose images to list.
     * @return array<int, array<string, mixed>> Image rows each containing a nested `variants` map.
     */
    public function listForPage(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        return $this->repo()->listForPage($pageId);
    }

    /**
     * Returns gallery-ready images for one public page, cover image first.
     *
     * Only images with `status = ready` and `include_in_gallery = true` are returned.
     * The cover image is moved to the front of the list while preserving the manual
     * sort order for remaining images.
     *
     * @param int $pageId Page id whose ready images to list.
     * @return array<int, array<string, mixed>> Gallery-ready image rows in display order.
     */
    public function listReadyForPublicPage(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        return $this->repo()->listReadyForPublicPage($pageId);
    }

    /**
     * Returns the public URL of the cover image for one page, or null when none is set.
     *
     * Only an explicit ready cover image is returned; a non-cover image does not qualify
     * even when it is the only gallery image. Returns null when no ready cover is found.
     *
     * @param int $pageId Page id whose cover image URL to resolve.
     * @return string|null Public cover image URL, or null when no cover image is available.
     */
    public function coverImageUrlForPage(int $pageId): ?string
    {
        if ($pageId < 1) {
            return null;
        }

        return $this->repo()->coverImageUrlForPage($pageId);
    }

    /**
     * Returns the injected page-image repository for repo-backed reads.
     *
     * @return PageImageRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function repo(): PageImageRead
    {
        if (!$this->pageImageRepo instanceof PageImageRead) {
            throw new RuntimeException('PageImageParser requires a PageImageRead for repository-backed reads.');
        }

        return $this->pageImageRepo;
    }
}
