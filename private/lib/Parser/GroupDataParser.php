<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/GroupDataParser.php
 * Repository-backed group read helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Core\Repository\GroupRead;
use Raven\Lib\Security\InputSanitizer;
use RuntimeException;

/**
 * Repository-backed group read helper.
 *
 * Exposes read-only group lookups used by public routes, panel list/edit flows, and the CLI.
 * Routing policy (prefixes, modes, registration mode) lives in GroupRouteParser.
 */
final class GroupDataParser
{
    private InputSanitizer $input;
    private ?GroupRead $groupRepo;

    /**
     * Initializes the group data reader.
     *
     * @param InputSanitizer       $input     Input normalizer used when validating slugs.
     * @param GroupRead|null $groupRepo Optional group repository for read-only group lookups.
     */
    public function __construct(InputSanitizer $input, ?GroupRead $groupRepo = null)
    {
        $this->input = $input;
        $this->groupRepo = $groupRepo;
    }

    /**
     * Returns all groups for read-only listing flows.
     *
     * @return array<int, array<string, mixed>> Group rows with member counts and role metadata.
     */
    public function listAll(): array
    {
        return $this->groupRepo()->listAll();
    }

    /**
     * Returns one panel group page plus total row count.
     *
     * @param int $limit  Maximum number of rows to return.
     * @param int $offset Zero-based row offset for pagination.
     * @return array{rows: array<int, array<string, mixed>>, total: int} Paginated group rows and total count.
     */
    public function listPageForPanel(int $limit = 50, int $offset = 0): array
    {
        return $this->groupRepo()->listPageForPanel(max(1, $limit), max(0, $offset));
    }

    /**
     * Returns one group row by numeric id.
     *
     * @param int $id Group id to resolve.
     * @return array<string, mixed>|null Group row, or null when not found.
     */
    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->groupRepo()->findById($id);
    }

    /**
     * Returns one group row by slug.
     *
     * @param string $slug Group slug to resolve.
     * @return array<string, mixed>|null Group row, or null when not found.
     */
    public function findBySlug(string $slug): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        $id = $this->groupRepo()->idBySlug($normalizedSlug);
        if (!is_int($id) || $id < 1) {
            return null;
        }

        return $this->groupRepo()->findById($id);
    }

    /**
     * Returns the numeric id for a group by its slug, or null when not found.
     *
     * @param string $slug Group slug to resolve.
     * @return int|null Group id, or null when slug does not match any group.
     */
    public function idBySlug(string $slug): ?int
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->groupRepo()->idBySlug($normalizedSlug);
    }

    /**
     * Returns flat name/slug option rows for group select menus.
     *
     * @return array<int, array{id: int, name: string, slug: string}> Group option rows ordered by name.
     */
    public function listOptions(): array
    {
        return $this->groupRepo()->listOptions();
    }

    /**
     * Returns the public group page record plus decorated member list for one group slug.
     *
     * @param string $slug Group slug to resolve.
     * @return array{group: array<string, mixed>, members: array<int, array<string, mixed>>}|null Group and member data, or null when not found.
     */
    public function findPublicRouteDataBySlug(string $slug): ?array
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null) {
            return null;
        }

        return $this->groupRepo()->findPublicRouteDataBySlug($normalizedSlug);
    }

    /**
     * Returns the injected group repository for repo-backed reads.
     *
     * @return GroupRead Repository backing canonical read methods.
     * @throws RuntimeException When no repository was injected at construction time.
     */
    private function groupRepo(): GroupRead
    {
        if (!$this->groupRepo instanceof GroupRead) {
            throw new RuntimeException('GroupDataParser requires a GroupRead for repository-backed reads.');
        }

        return $this->groupRepo;
    }
}
