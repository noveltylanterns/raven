<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Panel/RepoFactory.php
 * Panel repository and parser factory wiring extracted from panel runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Panel;

use Closure;
use Raven\Core\Repository\AuthWrite;
use Raven\Core\Repository\CategoryRead;
use Raven\Core\Repository\CategoryWrite;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\ChannelWrite;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\GroupWrite;
use Raven\Core\Repository\InviteRead;
use Raven\Core\Repository\InviteWrite;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\MediaWrite;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\PageWrite;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\RedirectWrite;
use Raven\Core\Repository\SetRead;
use Raven\Core\Repository\SetWrite;
use Raven\Core\Repository\TagRead;
use Raven\Core\Repository\TagWrite;
use Raven\Core\Repository\UserRead;
use Raven\Core\Repository\UserWrite;
use Raven\Lib\Parser\TaxonomyParser;

/**
 * Builds request-scoped memoized panel repository and parser factory closures.
 */
final class RepoFactory
{
    /**
     * Builds panel repository factory closures used by panel runtime wiring.
     *
     * @param array<string, mixed> $rvn Shared runtime container.
     * @param callable(callable): Closure $memoize Request-scoped memoization wrapper from panel runtime builder.
     * @param callable(): \PDO $resolveAuthDb Lazy auth-database resolver closure.
     * @param bool $categoryEnabled Whether category support is enabled for the current request.
     * @param bool $tagEnabled Whether tag support is enabled for the current request.
     * @return array{
     *   channel_read: Closure,
     *   channel_write: Closure,
     *   group_read: Closure,
     *   group_write: Closure,
     *   media_read: Closure,
     *   media_write: Closure,
     *   page_read: Closure,
     *   page_write: Closure,
     *   redirect_read: Closure,
     *   redirect_write: Closure,
     *   user_read: Closure,
     *   user_write: Closure,
     *   auth_write: Closure,
     *   category_set: Closure,
     *   tag_set: Closure,
     *   category_set_write: Closure,
     *   tag_set_write: Closure,
     *   invite_read: Closure,
     *   invite_write: Closure,
     *   category_read: Closure,
     *   category_write: Closure,
     *   tag_read: Closure,
     *   tag_write: Closure,
     *   taxonomy_lookup: Closure
     * } Map of memoized repo/parser factory closures used by panel runtime domains.
     */
    public static function build(
        array $rvn,
        callable $memoize,
        callable $resolveAuthDb,
        bool $categoryEnabled,
        bool $tagEnabled
    ): array {
        /**
         * Builds channel read side for panel content and routing flows.
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
         * Builds channel write side for panel channel-save and delete routes.
         */
        $channelWriteFactory = $memoize(static function () use ($rvn, $channelReadFactory): ChannelWrite {
            return new ChannelWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory(),
                (string) $rvn['root'] . '/private/dat/channel'
            );
        });

        /**
         * Builds group read side for panel group-listing flows.
         */
        $groupReadFactory = $memoize(static function () use ($rvn): GroupRead {
            return new GroupRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds group write side for panel group-save and delete routes.
         */
        $groupWriteFactory = $memoize(static function () use ($rvn, $groupReadFactory): GroupWrite {
            return new GroupWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $groupReadFactory()
            );
        });

        /**
         * Builds media read side for panel gallery renders and existence checks.
         */
        $mediaReadFactory = $memoize(static function () use ($rvn): MediaRead {
            return new MediaRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds media write side for panel gallery persistence.
         */
        $mediaWriteFactory = $memoize(static function () use ($rvn): MediaWrite {
            return new MediaWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds page read side for panel content/routing flows.
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
         * Builds page write side for panel page-save and delete routes.
         */
        $pageWriteFactory = $memoize(static function () use ($rvn, $channelReadFactory, $categoryEnabled, $tagEnabled): PageWrite {
            return new PageWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory(),
                $categoryEnabled,
                $tagEnabled
            );
        });

        /**
         * Builds redirect read side for panel routing inventory flows.
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
         * Builds redirect write side for panel redirect-save and delete routes.
         */
        $redirectWriteFactory = $memoize(static function () use ($rvn, $channelReadFactory): RedirectWrite {
            return new RedirectWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
            );
        });

        /**
         * Builds user read side for panel user listings and parser seams.
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
         * Builds user write side for panel user-save and delete routes.
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
         * Builds auth-user write side for preference and 2FA profile updates.
         */
        $authWriteFactory = $memoize(static function () use ($rvn, $resolveAuthDb): AuthWrite {
            return new AuthWrite(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds the file-backed category set read side only for panel taxonomy editors.
         */
        $categorySetFactory = $memoize(static function () use ($rvn): SetRead {
            return new SetRead('category', (string) $rvn['root'] . '/private/dat/category-set');
        });

        /**
         * Builds the file-backed tag set read side only for panel taxonomy editors.
         */
        $tagSetFactory = $memoize(static function () use ($rvn): SetRead {
            return new SetRead('tag', (string) $rvn['root'] . '/private/dat/tag-set');
        });

        /**
         * Builds the file-backed category set write side only for panel category-set save and delete routes.
         */
        $categorySetWriteFactory = $memoize(static function () use ($rvn, $categorySetFactory): SetWrite {
            return new SetWrite('category', (string) $rvn['root'] . '/private/dat/category-set', $categorySetFactory());
        });

        /**
         * Builds the file-backed tag set write side only for panel tag-set save and delete routes.
         */
        $tagSetWriteFactory = $memoize(static function () use ($rvn, $tagSetFactory): SetWrite {
            return new SetWrite('tag', (string) $rvn['root'] . '/private/dat/tag-set', $tagSetFactory());
        });

        /**
         * Builds invite read side only for panel invite listing.
         */
        $inviteReadFactory = $memoize(static function () use ($rvn, $resolveAuthDb): InviteRead {
            return new InviteRead(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds invite write side only for panel invite creation/deletion.
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
         * Builds category read side only for panel taxonomy flows that actually use categories.
         */
        $categoryReadFactory = $memoize(static function () use ($rvn): CategoryRead {
            return new CategoryRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds category write side only for panel category-save and delete routes.
         */
        $categoryWriteFactory = $memoize(static function () use ($rvn, $categoryReadFactory): CategoryWrite {
            return new CategoryWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $categoryReadFactory()
            );
        });

        /**
         * Builds tag read side only for panel taxonomy flows that actually use tags.
         */
        $tagReadFactory = $memoize(static function () use ($rvn): TagRead {
            return new TagRead(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );
        });

        /**
         * Builds tag write side only for panel tag-save and delete routes.
         */
        $tagWriteFactory = $memoize(static function () use ($rvn, $tagReadFactory): TagWrite {
            return new TagWrite(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $tagReadFactory()
            );
        });

        /**
         * Builds taxonomy lookup parsing only for routing and page-editor flows
         * that need category/tag option lookups beyond channel routing.
         */
        $taxonomyLookupFactory = $memoize(static function () use ($rvn, $channelReadFactory): TaxonomyParser {
            return new TaxonomyParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelReadFactory()
            );
        });

        return [
            'channel_read' => $channelReadFactory,
            'channel_write' => $channelWriteFactory,
            'group_read' => $groupReadFactory,
            'group_write' => $groupWriteFactory,
            'media_read' => $mediaReadFactory,
            'media_write' => $mediaWriteFactory,
            'page_read' => $pageReadFactory,
            'page_write' => $pageWriteFactory,
            'redirect_read' => $redirectReadFactory,
            'redirect_write' => $redirectWriteFactory,
            'user_read' => $userReadFactory,
            'user_write' => $userWriteFactory,
            'auth_write' => $authWriteFactory,
            'category_set' => $categorySetFactory,
            'tag_set' => $tagSetFactory,
            'category_set_write' => $categorySetWriteFactory,
            'tag_set_write' => $tagSetWriteFactory,
            'invite_read' => $inviteReadFactory,
            'invite_write' => $inviteWriteFactory,
            'category_read' => $categoryReadFactory,
            'category_write' => $categoryWriteFactory,
            'tag_read' => $tagReadFactory,
            'tag_write' => $tagWriteFactory,
            'taxonomy_lookup' => $taxonomyLookupFactory,
        ];
    }
}
