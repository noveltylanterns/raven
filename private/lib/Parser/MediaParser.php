<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/MediaParser.php
 * Read-only page media lookup helper backed by MediaRead.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\MediaRead;
use RuntimeException;

/**
 * Repository-backed page media read helper.
 *
 * Exposes read-only page-media queries for public rendering, panel preview,
 * and extension access. Write operations (insert, update gallery, delete)
 * live on MediaScribe, which the repository already delegates to internally.
 */
final class MediaParser
{
    private ?MediaRead $mediaRepo;

    /**
     * Prepares the media parser for read-only page-media lookups.
     *
     * @param MediaRead|null $mediaRepo Optional repository for read-only page-media queries.
     */
    public function __construct(?MediaRead $mediaRepo = null)
    {
        $this->mediaRepo = $mediaRepo;
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
     * Returns the injected media repository for repo-backed reads.
     *
     * @return MediaRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function repo(): MediaRead
    {
        if (!$this->mediaRepo instanceof MediaRead) {
            throw new RuntimeException('MediaParser requires a MediaRead for repository-backed reads.');
        }

        return $this->mediaRepo;
    }
}
