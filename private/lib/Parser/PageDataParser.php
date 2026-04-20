<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/PageDataParser.php
 * Repository-backed page read helper with routing-safe normalization.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\PageRepository;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed page read helper.
 *
 * Exposes read-only page lookups used by public routes, panel list/edit flows, and the CLI.
 * Static URL-segment resolution and route-segment building live in PageRouteParser.
 */
final class PageDataParser
{
    private InputSanitizer $input;
    private ?PageRepository $pageRepo;

    /**
     * Initializes the page data reader.
     *
     * @param InputSanitizer      $input    Input normalizer used when validating slugs and ids.
     * @param PageRepository|null $pageRepo Optional page repository for read-only page lookups.
     */
    public function __construct(InputSanitizer $input, ?PageRepository $pageRepo = null)
    {
        $this->input = $input;
        $this->pageRepo = $pageRepo;
    }

    /**
     * Returns the site homepage page row.
     *
     * @return array<string, mixed>|null Homepage page row, or null when none is published.
     */
    public function findHomepage(): ?array
    {
        return $this->pageRepo()->findHomepage();
    }

    /**
     * Returns the homepage page row for one channel.
     *
     * @param string $channelSlug Channel slug identifying the channel.
     * @return array{channel: array<string, mixed>, page: ?array<string, mixed>}|null Channel row plus its homepage page, or null when the channel does not exist.
     */
    public function findChannelHomepage(string $channelSlug): ?array
    {
        $normalizedChannelSlug = $this->normalizeChannelSlug($channelSlug, false);
        if ($normalizedChannelSlug === null) {
            return null;
        }

        return $this->pageRepo()->findChannelHomepage($normalizedChannelSlug);
    }

    /**
     * Returns one published page row for a public route by slug and optional channel.
     *
     * @param string      $pageSlug    Page slug to resolve.
     * @param string|null $channelSlug Optional channel slug to scope the lookup.
     * @return array<string, mixed>|null Published page row, or null when not found.
     */
    public function findPublicPage(string $pageSlug, ?string $channelSlug = null): ?array
    {
        $normalizedPageSlug = $this->input->slug($pageSlug);
        if ($normalizedPageSlug === null) {
            return null;
        }

        return $this->pageRepo()->findPublicPage($normalizedPageSlug, $this->normalizeChannelSlug($channelSlug));
    }

    /**
     * Returns one published page row for a public route by numeric id and optional channel.
     *
     * @param int         $pageId      Page id to resolve.
     * @param string|null $channelSlug Optional channel slug to scope the lookup.
     * @return array<string, mixed>|null Published page row, or null when not found.
     */
    public function findPublicPageById(int $pageId, ?string $channelSlug = null): ?array
    {
        if ($pageId < 1) {
            return null;
        }

        return $this->pageRepo()->findPublicPageById($pageId, $this->normalizeChannelSlug($channelSlug));
    }

    /**
     * Returns one page row by slug and optional channel scope.
     *
     * @param string               $pageSlug Page slug to resolve.
     * @param int|string|null      $channel  Optional channel id, slug, or null for root channel.
     * @return array<string, mixed>|null Page row, or null when not found.
     */
    public function findBySlug(string $pageSlug, int|string|null $channel = null): ?array
    {
        $normalizedPageSlug = $this->input->slug($pageSlug);
        if ($normalizedPageSlug === null) {
            return null;
        }

        $normalizedChannel = $this->normalizeChannelScope($channel);
        if ($normalizedChannel === false) {
            return null;
        }

        return $this->pageRepo()->findBySlug($normalizedPageSlug, $normalizedChannel);
    }

    /**
     * Returns the numeric id for a page by slug and optional channel scope.
     *
     * @param string          $pageSlug Page slug to resolve.
     * @param int|string|null $channel  Optional channel id, slug, or null for root channel.
     * @return int|null                 Page id, or null when not found.
     */
    public function idBySlug(string $pageSlug, int|string|null $channel = null): ?int
    {
        $normalizedPageSlug = $this->input->slug($pageSlug);
        if ($normalizedPageSlug === null) {
            return null;
        }

        $normalizedChannel = $this->normalizeChannelScope($channel);
        if ($normalizedChannel === false) {
            return null;
        }

        return $this->pageRepo()->idBySlug($normalizedPageSlug, $normalizedChannel);
    }

    /**
     * Returns the most recently published pages, optionally scoped to one channel.
     *
     * @param int         $limit       Maximum number of rows to return.
     * @param string|null $channelSlug Optional channel slug to scope the query.
     * @return array<int, array<string, mixed>> Published page rows ordered by publish date descending.
     */
    public function listRecentPublished(int $limit, ?string $channelSlug = null): array
    {
        return $this->pageRepo()->listRecentPublished(max(1, $limit), $this->normalizeChannelSlug($channelSlug));
    }

    /**
     * Returns the most recently published pages for a set of channels.
     *
     * @param int                $limit        Maximum number of rows to return.
     * @param array<int, string> $channelSlugs List of channel slugs to include.
     * @return array<int, array<string, mixed>> Published page rows ordered by publish date descending.
     */
    public function listRecentPublishedForChannels(int $limit, array $channelSlugs): array
    {
        $normalizedSlugs = [];
        foreach ($channelSlugs as $channelSlug) {
            $normalizedChannelSlug = $this->normalizeChannelSlug($channelSlug);
            if ($normalizedChannelSlug === null || $normalizedChannelSlug === '') {
                continue;
            }

            $normalizedSlugs[$normalizedChannelSlug] = $normalizedChannelSlug;
        }

        if ($normalizedSlugs === []) {
            return [];
        }

        return $this->pageRepo()->listRecentPublishedForChannels(max(1, $limit), array_values($normalizedSlugs));
    }

    /**
     * Returns the total number of pages visible in the panel list, optionally filtered.
     *
     * @param string|null $channelSlug Optional channel slug filter.
     * @param int|null    $categoryId  Optional category id filter.
     * @param int|null    $tagId       Optional tag id filter.
     * @return int                     Total matching page count.
     */
    public function countForPanel(?string $channelSlug = null, ?int $categoryId = null, ?int $tagId = null): int
    {
        return $this->pageRepo()->countForPanel(
            $this->normalizeChannelSlug($channelSlug),
            $this->normalizePositiveId($categoryId),
            $this->normalizePositiveId($tagId)
        );
    }

    /**
     * Returns one paginated page of panel page rows plus total count.
     *
     * @param int         $limit       Maximum number of rows to return.
     * @param int         $offset      Zero-based row offset for pagination.
     * @param string|null $channelSlug Optional channel slug filter.
     * @param int|null    $categoryId  Optional category id filter.
     * @param int|null    $tagId       Optional tag id filter.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageForPanel(
        int $limit = 50,
        int $offset = 0,
        ?string $channelSlug = null,
        ?int $categoryId = null,
        ?int $tagId = null
    ): array {
        return $this->pageRepo()->listPageForPanel(
            max(1, $limit),
            max(0, $offset),
            $this->normalizeChannelSlug($channelSlug),
            $this->normalizePositiveId($categoryId),
            $this->normalizePositiveId($tagId)
        );
    }

    /**
     * Returns all page rows needed to build the public routing table.
     *
     * @return array<int, array<string, mixed>> Minimal page rows: id, slug, channel, status, route fields.
     */
    public function listAllForRouting(): array
    {
        return $this->pageRepo()->listAllForRouting();
    }

    /**
     * Returns one page row by numeric id.
     *
     * @param int $id Page id to resolve.
     * @return array<string, mixed>|null Page row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->pageRepo()->findById($id);
    }

    /**
     * Returns the panel edit form data for one page by id, including gallery images.
     *
     * @param int $id Page id to load.
     * @return array{page: array<string, mixed>, gallery_images: array<int, array<string, mixed>>}|null Edit data, or null when not found.
     */
    public function editFormDataById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->pageRepo()->editFormDataById($id);
    }

    /**
     * Returns the category rows assigned to one page.
     *
     * @param int $pageId Page id to query.
     * @return array<int, array{id: int, name: string, slug: string}> Assigned category rows.
     */
    public function assignedCategoryRowsForPage(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        return $this->pageRepo()->assignedCategoryRowsForPage($pageId);
    }

    /**
     * Returns the tag rows assigned to one page.
     *
     * @param int $pageId Page id to query.
     * @return array<int, array{id: int, name: string, slug: string}> Assigned tag rows.
     */
    public function assignedTagRowsForPage(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        return $this->pageRepo()->assignedTagRowsForPage($pageId);
    }

    /**
     * Returns taxonomy assignment id arrays grouped by page id for a set of pages.
     *
     * @param array<int, mixed> $pageIds Page ids to query.
     * @return array<int, array{categories: array<int>, tags: array<int>}> Assignment map keyed by page id.
     */
    public function taxonomyAssignmentIdsByPage(array $pageIds): array
    {
        $normalizedPageIds = $this->normalizeIds($pageIds);
        if ($normalizedPageIds === []) {
            return [];
        }

        return $this->pageRepo()->taxonomyAssignmentIdsByPage($normalizedPageIds);
    }

    /**
     * Returns one paginated page of pages assigned to a category slug.
     *
     * @param string $slug   Category slug to filter by.
     * @param int    $limit  Maximum number of rows to return.
     * @param int    $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByCategorySlug(string $slug, int $limit, int $offset): array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->pageRepo()->listPageByCategorySlug($normalizedSlug, max(1, $limit), max(0, $offset));
    }

    /**
     * Returns one paginated page of pages assigned to a tag slug.
     *
     * @param string $slug   Tag slug to filter by.
     * @param int    $limit  Maximum number of rows to return.
     * @param int    $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated rows and total count.
     */
    public function listPageByTagSlug(string $slug, int $limit, int $offset): array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->pageRepo()->listPageByTagSlug($normalizedSlug, max(1, $limit), max(0, $offset));
    }

    /**
     * Returns the injected page repository for repo-backed reads.
     *
     * @return PageRepository Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function pageRepo(): PageRepository
    {
        if (!$this->pageRepo instanceof PageRepository) {
            throw new RuntimeException('PageDataParser requires a PageRepository for repository-backed reads.');
        }

        return $this->pageRepo;
    }

    /**
     * Normalizes a channel slug for use in repository queries.
     *
     * Optionally allows the root-channel sentinel so root-scoped queries can pass through.
     *
     * @param string|null $channelSlug Raw channel slug value.
     * @param bool        $allowRoot   When true, the root sentinel is accepted as-is.
     * @return string|null             Normalized slug, or null when blank or invalid.
     */
    private function normalizeChannelSlug(?string $channelSlug, bool $allowRoot = true): ?string
    {
        $normalized = strtolower(trim((string) ($channelSlug ?? '')));
        if ($normalized === '') {
            return null;
        }

        if ($allowRoot && $normalized === ChannelContextParser::ROOT_CHANNEL_SLUG) {
            return ChannelContextParser::ROOT_CHANNEL_SLUG;
        }

        return $this->input->slug($normalized);
    }

    /**
     * Normalizes a channel scope argument (id, slug, or null) for repository-level filtering.
     *
     * Returns false when the slug value is non-empty but fails slug normalization,
     * signaling that the caller should treat the lookup as a definitive miss.
     *
     * @param int|string|null $channel Channel id, slug string, or null for root channel scope.
     * @return int|string|null|false    Normalized channel scope, or false when a slug value is invalid.
     */
    private function normalizeChannelScope(int|string|null $channel): int|string|null|false
    {
        if ($channel === null) {
            return null;
        }

        if (is_int($channel)) {
            return $channel > 0 ? $channel : null;
        }

        $normalized = strtolower(trim($channel));
        if ($normalized === '' || $normalized === ChannelContextParser::ROOT_CHANNEL_SLUG) {
            return null;
        }

        $normalizedSlug = $this->input->slug($normalized);
        return $normalizedSlug ?? false;
    }

    /**
     * Normalizes an array of raw id values to a deduplicated list of positive integers.
     *
     * @param array<int, mixed> $ids Raw id values.
     * @return array<int>            Normalized positive integer ids.
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

    /**
     * Returns the id when it is a positive integer, or null otherwise.
     *
     * @param int|null $id Raw id value.
     * @return int|null    Positive id, or null when zero, negative, or not set.
     */
    private function normalizePositiveId(?int $id): ?int
    {
        return is_int($id) && $id > 0 ? $id : null;
    }
}
