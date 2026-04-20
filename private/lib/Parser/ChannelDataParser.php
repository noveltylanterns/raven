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
use Raven\Core\Repository\ChannelRepository;
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
    private ?ChannelRepository $channelRepo;

    /**
     * Initializes the channel data reader.
     *
     * @param Config                 $config      Runtime site configuration.
     * @param InputSanitizer         $input       Input normalizer used when validating slugs.
     * @param ChannelRepository|null $channelRepo Optional channel repository for read-only channel lookups.
     */
    public function __construct(Config $config, InputSanitizer $input, ?ChannelRepository $channelRepo = null)
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
     * Returns one panel channel page plus total row count.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated channel rows and total count.
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        return $this->channelRepo()->listPageForPanel(max(1, $limit), max(0, $offset));
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
     * Returns the injected channel repository for repo-backed reads.
     *
     * @return ChannelRepository Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function channelRepo(): ChannelRepository
    {
        if (!$this->channelRepo instanceof ChannelRepository) {
            throw new RuntimeException('ChannelDataParser requires a ChannelRepository for repository-backed reads.');
        }

        return $this->channelRepo;
    }
}
