<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Public/RepoFactories.php
 * Public repository and parser factory wiring extracted from public runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Public;

use Closure;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\InviteRead;
use Raven\Core\Repository\InviteWrite;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Repository\UserWrite;
use Raven\Lib\Parser\CategoryRepoParser;
use Raven\Lib\Parser\TagRepoParser;
use Raven\Lib\Parser\TaxonomyRepoParser;

/**
 * Builds request-scoped memoized public repository and parser factory closures.
 */
final class RepoFactories
{
    /**
     * Builds public repository and parser factories used by public runtime wiring.
     *
     * @param array<string, mixed> $rvn Shared runtime container.
     * @param callable(callable): Closure $memoize Request-scoped memoization wrapper from public runtime builder.
     * @param callable(): \PDO $resolveAuthDb Lazy auth-database resolver closure.
     * @param bool $categoryEnabled Whether category support is enabled for the current request.
     * @param bool $tagEnabled Whether tag support is enabled for the current request.
     * @return array{
     *   channel_read: Closure,
     *   group_read: Closure,
     *   media_read: Closure,
     *   page_read: Closure,
     *   redirect_read: Closure,
     *   user_read: Closure,
     *   user_write: Closure,
     *   invite_read: Closure,
     *   invite_write: Closure,
     *   taxonomy_lookup: Closure,
     *   category_lookup: Closure,
     *   tag_lookup: Closure
     * } Map of memoized repo/parser factory closures used by public runtime domains.
     */
    public static function build(
        array $rvn,
        callable $memoize,
        callable $resolveAuthDb,
        bool $categoryEnabled,
        bool $tagEnabled
    ): array {
        /**
         * Builds channel read side for public routing/content flows.
         */
        $channelReadFactory = $memoize(static function () use ($rvn): ChannelRead {
            return new ChannelRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                (string) $rvn['root'] . '/private/dat/channel'
            );
        });

        /**
         * Builds group read side for public auth/profile flows.
         */
        $groupReadFactory = $memoize(static function () use ($rvn): GroupRead {
            return new GroupRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds media read side only when public rendering needs media rows.
         */
        $mediaReadFactory = $memoize(static function () use ($rvn): MediaRead {
            return new MediaRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds page read side for public content routes.
         */
        $pageReadFactory = $memoize(static function () use ($rvn, $channelReadFactory, $categoryEnabled, $tagEnabled): PageRead {
            return new PageRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory(),
                $categoryEnabled,
                $tagEnabled
            );
        });

        /**
         * Builds redirect read side for public redirect fallbacks and routing helpers.
         */
        $redirectReadFactory = $memoize(static function () use ($rvn, $channelReadFactory): RedirectRead {
            return new RedirectRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
            );
        });

        /**
         * Builds user read side for public author lookups and profile flows.
         */
        $userReadFactory = $memoize(static function () use ($rvn, $resolveAuthDb): UserRead {
            return new UserRead(
                $resolveAuthDb(),
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds user write side for public login/register flows.
         */
        $userWriteFactory = $memoize(static function () use ($rvn, $resolveAuthDb): UserWrite {
            return new UserWrite(
                $resolveAuthDb(),
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds invite-token read side only for the registration flows that validate tokens.
         */
        $inviteReadFactory = $memoize(static function () use ($rvn, $resolveAuthDb): InviteRead {
            return new InviteRead(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds invite-token write side only for the registration flows that consume tokens.
         */
        $inviteWriteFactory = $memoize(static function () use ($rvn, $resolveAuthDb, $inviteReadFactory): InviteWrite {
            return new InviteWrite(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $inviteReadFactory()
            );
        });

        /**
         * Builds taxonomy lookup parsing only for public routes that actually resolve channel/category/tag slugs.
         */
        $taxonomyLookupRepository = $memoize(static function () use ($rvn, $channelReadFactory): TaxonomyRepoParser {
            return new TaxonomyRepoParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
            );
        });

        /**
         * Builds category lookup parsing only for public category-route and taxonomy-feed reads.
         */
        $categoryLookupRepository = $memoize(static function () use ($rvn): CategoryRepoParser {
            return new CategoryRepoParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds tag lookup parsing only for public tag-route and taxonomy-feed reads.
         */
        $tagLookupRepository = $memoize(static function () use ($rvn): TagRepoParser {
            return new TagRepoParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        return [
            'channel_read' => $channelReadFactory,
            'group_read' => $groupReadFactory,
            'media_read' => $mediaReadFactory,
            'page_read' => $pageReadFactory,
            'redirect_read' => $redirectReadFactory,
            'user_read' => $userReadFactory,
            'user_write' => $userWriteFactory,
            'invite_read' => $inviteReadFactory,
            'invite_write' => $inviteWriteFactory,
            'taxonomy_lookup' => $taxonomyLookupRepository,
            'category_lookup' => $categoryLookupRepository,
            'tag_lookup' => $tagLookupRepository,
        ];
    }
}
