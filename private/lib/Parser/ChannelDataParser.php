<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ChannelDataParser.php
 * Repository-backed channel read helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Config;
use Raven\Core\Repository\ChannelRead;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed channel read helper.
 *
 * Exposes read-only channel lookups used by panel list/edit flows, the CLI, and routing inventory.
 * Channel routing policy (route modes, separators) lives in ChannelRouteParser.
 * Category and tag routing policy (enabled flags, prefixes) lives in CategoryRouteParser and TagRouteParser.
 */
final class ChannelDataParser
{
    private Config $config;
    private InputSanitizer $input;
    private ?ChannelRead $channelRepo;

    /**
     * Initializes the channel data reader.
     *
     * @param Config                 $config      Runtime site configuration.
     * @param InputSanitizer         $input       Input normalizer used when validating slugs.
     * @param ChannelRead|null $channelRepo Optional channel repository for read-only channel lookups.
     */
    public function __construct(Config $config, InputSanitizer $input, ?ChannelRead $channelRepo = null)
    {
        $this->config = $config;
        $this->input = $input;
        $this->channelRepo = $channelRepo;
    }

    /**
     * Returns all channels for read-only listing flows.
     *
     * @return array<int, array<string, mixed>> Channel rows with attached counts/context.
     */
    public function listAll(): array
    {
        return $this->channelRepo()->listAll();
    }

    /**
     * Returns one paginated channel page plus total row count.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated channel rows and total count.
     */
    public function listPage(int $limit = 50, int $offset = 0): array
    {
        return $this->channelRepo()->listPage(max(1, $limit), max(0, $offset));
    }

    /**
     * Returns one channel row by numeric id.
     *
     * @param int $id Channel id to resolve.
     * @return array<string, mixed>|null Channel row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 0) {
            return null;
        }

        return $this->channelRepo()->findById($id);
    }

    /**
     * Returns one channel row by slug.
     *
     * @param string $slug Channel slug to resolve.
     * @return array<string, mixed>|null Channel row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->channelRepo()->findBySlug($normalizedSlug);
    }

    /**
     * Returns whether one normalized channel slug already exists.
     *
     * @param string $slug Channel slug to validate.
     * @return bool True when the slug already belongs to an existing channel row.
     */
    public function slugExists(string $slug): bool
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return false;
        }

        return $this->channelRepo()->slugExists($normalizedSlug);
    }

    /**
     * Returns one channel id by slug.
     *
     * @param string $slug Channel slug to resolve.
     * @return int|null Channel id, or null when not found.
     */
    public function idBySlug(string $slug): ?int
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->channelRepo()->idBySlug($normalizedSlug);
    }

    /**
     * Returns routing-oriented channel options including the stock root channel.
     *
     * @return array<int, array<string, mixed>> Routing option rows.
     */
    public function listRoutingOptions(): array
    {
        return $this->channelRepo()->listRoutingOptions();
    }

    /**
     * Returns editor-facing channel option rows.
     *
     * @return array<int, array<string, mixed>> Channel option rows.
     */
    public function listOptions(): array
    {
        return $this->channelRepo()->listOptions();
    }

    /**
     * Returns how many channels explicitly reference one taxonomy set id.
     *
     * @param string $kind Taxonomy kind key (`category` or `tag`).
     * @param int $setId Taxonomy set id to count.
     * @return int Number of channels that explicitly select the given set id.
     */
    public function countExplicitTaxonomySetAssignments(string $kind, int $setId): int
    {
        if ($setId < 1) {
            return 0;
        }

        return (int) ($this->explicitTaxonomySetCounts($kind)[$setId] ?? 0);
    }

    /**
     * Returns channel assignment counts keyed by taxonomy set id.
     *
     * @param string $kind Taxonomy kind key (`category` or `tag`).
     * @return array<int, int> Channel counts keyed by taxonomy set id.
     */
    public function explicitTaxonomySetCounts(string $kind): array
    {
        $field = strtolower(trim($kind)) === 'tag' ? 'tag_sets' : 'category_sets';
        $counts = [];

        foreach ($this->listRoutingOptions() as $channel) {
            $selection = $channel[$field] ?? [];
            if (!is_array($selection)) {
                continue;
            }

            foreach ($selection as $selectedSetId) {
                $normalizedSetId = is_int($selectedSetId) ? $selectedSetId : (int) $selectedSetId;
                if ($normalizedSetId < 1) {
                    continue;
                }

                $counts[$normalizedSetId] = (int) ($counts[$normalizedSetId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Returns the injected channel repository for repo-backed reads.
     *
     * @return ChannelRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function channelRepo(): ChannelRead
    {
        if (!$this->channelRepo instanceof ChannelRead) {
            throw new RuntimeException('ChannelDataParser requires a ChannelRead for repository-backed reads.');
        }

        return $this->channelRepo;
    }
}
