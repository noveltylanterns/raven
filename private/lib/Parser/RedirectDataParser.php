<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/RedirectDataParser.php
 * Read-only redirect lookup and panel-list parser backed by RedirectRepository.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\RedirectRepository;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed redirect read helper with shared slug/channel normalization.
 */
final class RedirectDataParser
{
    private InputSanitizer $input;
    private ?RedirectRepository $redirectRepo;

    /**
     * Prepares the redirect data parser for normalized read-only redirect lookups.
     *
     * @param InputSanitizer          $input        Shared input sanitizer for redirect selector normalization.
     * @param RedirectRepository|null $redirectRepo Optional redirect repository used for read-only redirect lookups.
     */
    public function __construct(InputSanitizer $input, ?RedirectRepository $redirectRepo = null)
    {
        $this->input = $input;
        $this->redirectRepo = $redirectRepo;
    }

    /**
     * Returns all redirects for read-only listing flows.
     *
     * @return array<int, array<string, mixed>> Redirect rows with attached channel context.
     */
    public function listAll(): array
    {
        return $this->redirectRepo()->listAll();
    }

    /**
     * Returns one panel redirect page plus total row count.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated redirect rows and total count.
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        return $this->redirectRepo()->listPageForPanel(max(1, $limit), max(0, $offset));
    }

    /**
     * Returns one redirect row by numeric id.
     *
     * @param int $id Redirect id to resolve.
     * @return array<string, mixed>|null Redirect row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->redirectRepo()->findById($id);
    }

    /**
     * Returns one redirect row by slug and optional channel scope.
     *
     * @param string          $slug    Redirect slug to resolve.
     * @param int|string|null $channel Channel id, channel slug, or null for root scope.
     * @return array<string, mixed>|null Redirect row, or null when not found.
     */
    public function findBySlug(string $slug, int|string|null $channel = null): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        $normalizedChannel = $this->normalizeChannelScope($channel);
        if ($normalizedChannel === false) {
            return null;
        }

        return $this->redirectRepo()->findBySlug($normalizedSlug, $normalizedChannel);
    }

    /**
     * Returns the first active redirect matching a path and optional channel scope.
     *
     * Used by public route controllers to detect redirect fallbacks before returning 404.
     * Only active redirects are returned; inactive rows are excluded by the repository query.
     *
     * @param string      $slug        URL slug segment to match against redirect path column.
     * @param string|null $channelSlug Channel slug scope, or null for root-scope redirects.
     * @return array<string, mixed>|null Active redirect row with channel context, or null when none matches.
     */
    public function findActiveByPath(string $slug, ?string $channelSlug = null): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        $normalizedChannel = $channelSlug !== null ? $this->input->slug($channelSlug) : null;

        return $this->redirectRepo()->findActiveByPath($normalizedSlug, $normalizedChannel);
    }

    /**
     * Returns the injected redirect repository for repo-backed reads.
     *
     * @return RedirectRepository Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function redirectRepo(): RedirectRepository
    {
        if (!$this->redirectRepo instanceof RedirectRepository) {
            throw new RuntimeException('RedirectDataParser requires a RedirectRepository for repository-backed reads.');
        }

        return $this->redirectRepo;
    }

    /**
     * Normalizes a redirect channel selector to repository-safe scope values.
     *
     * Returns false when a slug value is non-empty but fails normalization,
     * signaling that the caller should treat the lookup as a definitive miss.
     *
     * @param int|string|null $channel Raw channel selector.
     * @return int|string|null|false   Normalized selector, or false when invalid.
     */
    private function normalizeChannelScope(int|string|null $channel): int|string|null|false
    {
        if ($channel === null) {
            return null;
        }

        if (is_int($channel)) {
            return $channel > 0 ? $channel : null;
        }

        $normalizedSlug = $this->input->slug($channel);
        if ($normalizedSlug === null) {
            return false;
        }

        return $normalizedSlug;
    }
}
