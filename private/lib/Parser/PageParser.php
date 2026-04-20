<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/PageParser.php
 * Read-only page lookup, routing, and panel-list parser backed by PageRepository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\PageRepository;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed page read helper with routing-safe normalization.
 */
final class PageParser
{
    private InputSanitizer $input;
    private ?PageRepository $pageRepo;

    /**
     * @param InputSanitizer    $input
     * @param PageRepository|null $pageRepo
     */
    public function __construct(InputSanitizer $input, ?PageRepository $pageRepo = null)
    {
        $this->input = $input;
        $this->pageRepo = $pageRepo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findHomepage(): ?array
    {
        return $this->pageRepo()->findHomepage();
    }

    /**
     * @return array{channel: array<string, mixed>, page: ?array<string, mixed>}|null
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
     * @return array<string, mixed>|null
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
     * @return array<string, mixed>|null
     */
    public function findPublicPageById(int $pageId, ?string $channelSlug = null): ?array
    {
        if ($pageId < 1) {
            return null;
        }

        return $this->pageRepo()->findPublicPageById($pageId, $this->normalizeChannelSlug($channelSlug));
    }

    /**
     * @return array<string, mixed>|null
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
     * @return array<int, array<string, mixed>>
     */
    public function listRecentPublished(int $limit, ?string $channelSlug = null): array
    {
        return $this->pageRepo()->listRecentPublished(max(1, $limit), $this->normalizeChannelSlug($channelSlug));
    }

    /**
     * @param array<int, string> $channelSlugs
     * @return array<int, array<string, mixed>>
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

    public function countForPanel(?string $channelSlug = null, ?int $categoryId = null, ?int $tagId = null): int
    {
        return $this->pageRepo()->countForPanel(
            $this->normalizeChannelSlug($channelSlug),
            $this->normalizePositiveId($categoryId),
            $this->normalizePositiveId($tagId)
        );
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
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
     * @return array<int, array<string, mixed>>
     */
    public function listAllForRouting(): array
    {
        return $this->pageRepo()->listAllForRouting();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->pageRepo()->findById($id);
    }

    /**
     * @return array{page: array<string, mixed>, gallery_images: array<int, array<string, mixed>>}|null
     */
    public function editFormDataById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->pageRepo()->editFormDataById($id);
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function assignedCategoryRowsForPage(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        return $this->pageRepo()->assignedCategoryRowsForPage($pageId);
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function assignedTagRowsForPage(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        return $this->pageRepo()->assignedTagRowsForPage($pageId);
    }

    /**
     * @param array<int, mixed> $pageIds
     * @return array<int, array{categories: array<int>, tags: array<int>}>
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
     * @return array{rows: array<int, array<string, mixed>>, total: int}
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
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listPageByTagSlug(string $slug, int $limit, int $offset): array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return ['rows' => [], 'total' => 0];
        }

        return $this->pageRepo()->listPageByTagSlug($normalizedSlug, max(1, $limit), max(0, $offset));
    }

    private function pageRepo(): PageRepository
    {
        if (!$this->pageRepo instanceof PageRepository) {
            throw new RuntimeException('PageParser requires a PageRepository for repository-backed reads.');
        }

        return $this->pageRepo;
    }

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
     * @param array<int, mixed> $ids
     * @return array<int>
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

    private function normalizePositiveId(?int $id): ?int
    {
        return is_int($id) && $id > 0 ? $id : null;
    }
}
