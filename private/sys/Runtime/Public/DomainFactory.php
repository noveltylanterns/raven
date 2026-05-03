<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Public/DomainFactory.php
 * Public domain-aggregate factory wiring extracted from public runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Public;

use Closure;

/**
 * Builds request-scoped memoized public domain aggregate closures.
 */
final class DomainFactory
{
    /**
     * Builds domain aggregate closures consumed by public controllers.
     *
     * @param callable(callable): Closure $memoize Request-scoped memoization wrapper from public runtime builder.
     * @param Closure $channelReadFactory Channel read factory closure.
     * @param Closure $categoryLookupRepository Category lookup parser factory closure.
     * @param Closure $mediaReadFactory Media read factory closure.
     * @param Closure $pageReadFactory Page read factory closure.
     * @param Closure $redirectReadFactory Redirect read factory closure.
     * @param Closure $tagLookupRepository Tag lookup parser factory closure.
     * @param Closure $taxonomyLookupRepository Taxonomy lookup parser factory closure.
     * @param Closure $groupReadFactory Group read factory closure.
     * @param Closure $userReadFactory User read factory closure.
     * @param Closure $userWriteFactory User write factory closure.
     * @param Closure $inviteReadFactory Invite read factory closure.
     * @param Closure $inviteWriteFactory Invite write factory closure.
     * @param callable(?string): array<string, mixed> $extensionServicesProvider Extension service resolver closure.
     * @return array{
     *   public_domain_content: Closure,
     *   public_domain_auth: Closure,
     *   public_domain_form: Closure
     * } Domain aggregate closure map for public runtime container wiring.
     */
    public static function build(
        callable $memoize,
        Closure $channelReadFactory,
        Closure $categoryLookupRepository,
        Closure $mediaReadFactory,
        Closure $pageReadFactory,
        Closure $redirectReadFactory,
        Closure $tagLookupRepository,
        Closure $taxonomyLookupRepository,
        Closure $groupReadFactory,
        Closure $userReadFactory,
        Closure $userWriteFactory,
        Closure $inviteReadFactory,
        Closure $inviteWriteFactory,
        callable $extensionServicesProvider
    ): array {
        /**
         * Public content/feed routes share the same page/channel/redirect runtime
         * dependencies, so expose them as one clustered factory ahead of the later
         * sub-controller split.
         *
         * @return array<string, mixed>
         */
        $publicContentDomain = $memoize(static function () use (
            $channelReadFactory,
            $categoryLookupRepository,
            $mediaReadFactory,
            $pageReadFactory,
            $redirectReadFactory,
            $tagLookupRepository,
            $taxonomyLookupRepository
        ): array {
            return [
                'category_lookup' => $categoryLookupRepository,
                'channel' => $channelReadFactory(),
                'media' => $mediaReadFactory(),
                'page' => $pageReadFactory(),
                'redirect_read' => $redirectReadFactory(),
                'tag_lookup' => $tagLookupRepository,
                'taxonomy_lookup' => $taxonomyLookupRepository,
            ];
        });

        /**
         * Public auth/profile routes share account-facing repositories; keep that
         * seam visible now so login/register/profile can split cleanly later.
         *
         * @return array<string, mixed>
         */
        $publicAuthDomain = $memoize(static function () use (
            $groupReadFactory,
            $userReadFactory,
            $userWriteFactory,
            $inviteReadFactory,
            $inviteWriteFactory
        ): array {
            return [
                'group' => $groupReadFactory(),
                'user_read' => $userReadFactory(),
                'user_write' => $userWriteFactory(),
                'invite_read' => $inviteReadFactory,
                'invite_write' => $inviteWriteFactory,
            ];
        });

        /**
         * Embedded-form and shortcode routes depend on extension services but
         * should stay lazy on ordinary public page requests.
         *
         * @return array<string, mixed>
         */
        $publicFormDomain = $memoize(static function () use ($extensionServicesProvider): array {
            return [
                'extension_services' => $extensionServicesProvider,
            ];
        });

        return [
            'public_domain_content' => $publicContentDomain,
            'public_domain_auth' => $publicAuthDomain,
            'public_domain_form' => $publicFormDomain,
        ];
    }
}
