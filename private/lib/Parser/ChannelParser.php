<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/ChannelParser.php
 * Channel slug resolution helpers shared by write-side repository classes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use RuntimeException;

/**
 * Write-side channel data helpers.
 *
 * Holds shared channel resolution logic used by repository write classes
 * (PageWrite, RedirectWrite) that need to resolve a channel id from a slug
 * before persisting records, without depending on ChannelRead directly.
 */
final class ChannelParser
{
    /**
     * Resolves a channel id from a slug string using a caller-provided lookup callback.
     *
     * The callback pattern keeps write-side repositories decoupled from ChannelRead:
     * callers pass their own `idBySlug` resolver so this helper carries no DB dependency.
     *
     * @param string|null $slug             Raw slug to resolve; empty/null returns null without error.
     * @param callable    $idBySlugResolver Callback accepting a normalized slug string and returning int|null.
     * @param string      $missingMessage   Exception message when the slug is non-empty but resolves to nothing.
     * @return int|null                     Resolved channel id, or null when slug is empty.
     * @throws RuntimeException When the slug is non-empty but resolves to no known channel.
     */
    public static function resolveChannelIdBySlug(
        ?string $slug,
        callable $idBySlugResolver,
        string $missingMessage = 'Selected channel does not exist.'
    ): ?int {
        $normalized = strtolower(trim((string) ($slug ?? '')));
        // Empty slugs intentionally map to null so callers can treat channel as optional.
        if ($normalized === '') {
            return null;
        }

        $resolved = $idBySlugResolver($normalized);
        $id = $resolved !== null ? (int) $resolved : null;
        // Non-positive/unknown ids are treated as missing channel selections.
        if ($id === null || $id < 1) {
            throw new RuntimeException($missingMessage);
        }

        return $id;
    }
}
