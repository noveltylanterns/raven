<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Panel/DomainFactory.php
 * Panel domain-aggregate factory wiring extracted from panel runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Panel;

use Closure;

/**
 * Builds request-scoped memoized panel domain aggregate closures.
 */
final class DomainFactory
{
    /**
     * Builds domain aggregate closures consumed by panel controllers.
     *
     * @param callable(callable): Closure $memoize Request-scoped memoization wrapper from panel runtime builder.
     * @param Closure $channelReadFactory Channel read factory closure.
     * @param Closure $channelWriteFactory Channel write factory closure.
     * @param Closure $pageReadFactory Page read factory closure.
     * @param Closure $pageWriteFactory Page write factory closure.
     * @param Closure $mediaReadFactory Media read factory closure.
     * @param Closure $mediaWriteFactory Media write factory closure.
     * @param Closure $mediaManagerFactory Media manager factory closure.
     * @param Closure $userReadFactory User read factory closure.
     * @param Closure $userWriteFactory User write factory closure.
     * @param Closure $groupReadFactory Group read factory closure.
     * @param Closure $groupWriteFactory Group write factory closure.
     * @param Closure $inviteReadFactory Invite read factory closure.
     * @param Closure $inviteWriteFactory Invite write factory closure.
     * @param Closure $redirectReadFactory Redirect read factory closure.
     * @param Closure $redirectWriteFactory Redirect write factory closure.
     * @param Closure $categoryReadFactory Category read factory closure.
     * @param Closure $categoryWriteFactory Category write factory closure.
     * @param Closure $categorySetFactory Category set read factory closure.
     * @param Closure $categorySetWriteFactory Category set write factory closure.
     * @param Closure $tagReadFactory Tag read factory closure.
     * @param Closure $tagWriteFactory Tag write factory closure.
     * @param Closure $tagSetFactory Tag set read factory closure.
     * @param Closure $tagSetWriteFactory Tag set write factory closure.
     * @param Closure $taxonomyLookupFactory Taxonomy lookup parser factory closure.
     * @param bool $categoryEnabled Whether category support is enabled for the current request.
     * @param bool $tagEnabled Whether tag support is enabled for the current request.
     * @return array{
     *   panel_domain_content: Closure,
     *   panel_domain_taxonomy: Closure,
     *   panel_domain_user: Closure,
     *   panel_domain_preferences: Closure,
     *   panel_domain_system: Closure
     * } Domain aggregate closure map for panel runtime container wiring.
     */
    public static function build(
        callable $memoize,
        Closure $channelReadFactory,
        Closure $channelWriteFactory,
        Closure $pageReadFactory,
        Closure $pageWriteFactory,
        Closure $mediaReadFactory,
        Closure $mediaWriteFactory,
        Closure $mediaManagerFactory,
        Closure $userReadFactory,
        Closure $userWriteFactory,
        Closure $groupReadFactory,
        Closure $groupWriteFactory,
        Closure $inviteReadFactory,
        Closure $inviteWriteFactory,
        Closure $redirectReadFactory,
        Closure $redirectWriteFactory,
        Closure $categoryReadFactory,
        Closure $categoryWriteFactory,
        Closure $categorySetFactory,
        Closure $categorySetWriteFactory,
        Closure $tagReadFactory,
        Closure $tagWriteFactory,
        Closure $tagSetFactory,
        Closure $tagSetWriteFactory,
        Closure $taxonomyLookupFactory,
        bool $categoryEnabled,
        bool $tagEnabled
    ): array {
        /**
         * Content routes share page/channel/media/user dependencies mapped to the
         * panel content sub-controller. User storage is included because page-editor
         * author validation and author select options need the user repository.
         *
         * @return array<string, mixed>
         */
        $panelContentDomain = $memoize(static function () use (
            $channelReadFactory,
            $pageReadFactory,
            $pageWriteFactory,
            $mediaReadFactory,
            $mediaWriteFactory,
            $mediaManagerFactory,
            $userReadFactory
        ): array {
            return [
                'channel_read' => $channelReadFactory(),
                'page_read' => $pageReadFactory(),
                'page_write' => $pageWriteFactory(),
                'media_read' => $mediaReadFactory(),
                'media_write' => $mediaWriteFactory(),
                'media_manager' => $mediaManagerFactory,
                'user_read' => $userReadFactory(),
            ];
        });

        /**
         * Taxonomy routes share channel/routing deps plus lazy category/tag
         * resolvers, matching the future taxonomy sub-controller seam.
         *
         * @return array<string, mixed>
         */
        $panelTaxonomyDomain = $memoize(static function () use (
            $channelReadFactory,
            $channelWriteFactory,
            $redirectReadFactory,
            $redirectWriteFactory,
            $categoryReadFactory,
            $categoryWriteFactory,
            $categorySetFactory,
            $categorySetWriteFactory,
            $tagReadFactory,
            $tagWriteFactory,
            $tagSetFactory,
            $tagSetWriteFactory,
            $taxonomyLookupFactory,
            $categoryEnabled,
            $tagEnabled
        ): array {
            return [
                'channel_read' => $channelReadFactory(),
                'channel_write' => $channelWriteFactory(),
                'redirect_read' => $redirectReadFactory(),
                'redirect_write' => $redirectWriteFactory(),
                'category' => $categoryReadFactory,
                'category_write' => $categoryWriteFactory,
                'category_set' => $categorySetFactory,
                'category_set_write' => $categorySetWriteFactory,
                'tag' => $tagReadFactory,
                'tag_write' => $tagWriteFactory,
                'tag_set' => $tagSetFactory,
                'tag_set_write' => $tagSetWriteFactory,
                'taxonomy_lookup' => $taxonomyLookupFactory,
                'category_enabled' => $categoryEnabled,
                'tag_enabled' => $tagEnabled,
            ];
        });

        /**
         * User/group/invite deps stay clustered so account-facing routes can split
         * away from the monolith without another bootstrap rewrite.
         *
         * @return array<string, mixed>
         */
        $panelUserDomain = $memoize(static function () use (
            $groupReadFactory,
            $groupWriteFactory,
            $userReadFactory,
            $userWriteFactory,
            $inviteReadFactory,
            $inviteWriteFactory
        ): array {
            return [
                'group_read' => $groupReadFactory(),
                'group_write' => $groupWriteFactory(),
                'user_read' => $userReadFactory(),
                'user_write' => $userWriteFactory(),
                'invite_read' => $inviteReadFactory,
                'invite_write' => $inviteWriteFactory,
            ];
        });

        /**
         * Preferences currently share the user/group account seam.
         *
         * @return array<string, mixed>
         */
        $panelPreferencesDomain = $panelUserDomain;

        /**
         * System routes currently need the same routing/content seams.
         *
         * @return array<string, mixed>
         */
        $panelSystemDomain = $memoize(static function () use (
            $channelReadFactory,
            $categorySetFactory,
            $pageReadFactory,
            $redirectReadFactory,
            $tagSetFactory,
            $taxonomyLookupFactory,
            $userReadFactory
        ): array {
            return [
                'channel' => $channelReadFactory(),
                'category_set' => $categorySetFactory,
                'page' => $pageReadFactory(),
                'redirect' => $redirectReadFactory(),
                'tag_set' => $tagSetFactory,
                'taxonomy_lookup' => $taxonomyLookupFactory,
                'user' => $userReadFactory(),
            ];
        });

        return [
            'panel_domain_content' => $panelContentDomain,
            'panel_domain_taxonomy' => $panelTaxonomyDomain,
            'panel_domain_user' => $panelUserDomain,
            'panel_domain_preferences' => $panelPreferencesDomain,
            'panel_domain_system' => $panelSystemDomain,
        ];
    }
}
