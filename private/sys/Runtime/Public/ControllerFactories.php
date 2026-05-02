<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Public/ControllerFactories.php
 * Public controller-factory closure wiring extracted from public runtime builder.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Public;

use Closure;
use Raven\Core\Controller\Public\AuthController as PublicAuthController;
use Raven\Core\Controller\Public\CategoryController as PublicCategoryController;
use Raven\Core\Controller\Public\ChannelController as PublicChannelController;
use Raven\Core\Controller\Public\FeedController as PublicFeedController;
use Raven\Core\Controller\Public\GroupController as PublicGroupController;
use Raven\Core\Controller\Public\PageController as PublicPageController;
use Raven\Core\Controller\Public\SharedController;
use Raven\Core\Controller\Public\TagController as PublicTagController;
use Raven\Core\Controller\Public\UserController as PublicUserController;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\View\Public\ThemeCatalog;

/**
 * Registers public controller factory closures onto the runtime container.
 */
final class ControllerFactories
{
    /**
     * Registers public request-context and controller factory closures.
     *
     * @param array<string, mixed> $rvn Shared runtime container, mutated in-place.
     * @param callable(): AuthService $resolveAuth Lazy auth-service resolver.
     * @param Closure $themeCatalogFactory Theme catalog factory closure.
     * @param Closure $extensionEditorCatalogFactory Extension editor catalog factory closure.
     * @param Closure $publicContentDomain Public content domain aggregate factory closure.
     * @param Closure $publicAuthDomain Public auth/profile domain aggregate factory closure.
     * @param Closure $publicFormDomain Public form domain aggregate factory closure.
     * @param callable(?string): array<string, mixed> $extensionServicesProvider Extension service resolver closure.
     * @return void
     */
    public static function register(
        array &$rvn,
        callable $resolveAuth,
        Closure $themeCatalogFactory,
        Closure $extensionEditorCatalogFactory,
        Closure $publicContentDomain,
        Closure $publicAuthDomain,
        Closure $publicFormDomain,
        callable $extensionServicesProvider
    ): void {
        $publicAuthController = null;
        $publicPageController = null;
        $publicFeedController = null;
        $publicCategoryController = null;
        $publicChannelController = null;
        $publicGroupController = null;
        $publicTagController = null;
        $publicUserController = null;
        $publicSharedController = null;

        /**
         * Builds the shared request context for split public sub-controllers.
         */
        $rvn['public_request_context'] = static function () use (&$publicSharedController, $rvn, $resolveAuth, $themeCatalogFactory): SharedController {
            if ($publicSharedController instanceof SharedController) {
                return $publicSharedController;
            }

            $publicSharedController = new SharedController(
                $rvn['config'],
                $resolveAuth(),
                $rvn['input'],
                $rvn['csrf'],
                $themeCatalogFactory()
            );

            return $publicSharedController;
        };

        /**
         * Builds the split public auth controller on first use.
         */
        $rvn['public_auth_controller'] = static function () use (&$publicAuthController, &$rvn, $publicAuthDomain): PublicAuthController {
            if ($publicAuthController instanceof PublicAuthController) {
                return $publicAuthController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $authDomain = $publicAuthDomain();
            $publicAuthController = new PublicAuthController(
                $requestContextFactory(),
                $authDomain['group'],
                $authDomain['user_write'],
                $authDomain['invite_read'],
                $authDomain['invite_write']
            );

            return $publicAuthController;
        };

        /**
         * Builds the split public channel controller on first use.
         */
        $rvn['public_channel_controller'] = static function () use (&$publicChannelController, &$rvn, $publicContentDomain, $publicAuthDomain, $publicFormDomain, $themeCatalogFactory, $extensionEditorCatalogFactory): PublicChannelController {
            if ($publicChannelController instanceof PublicChannelController) {
                return $publicChannelController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $authDomain = $publicAuthDomain();
            $formDomain = $publicFormDomain();
            $publicChannelController = new PublicChannelController(
                $requestContextFactory(),
                $contentDomain['media'],
                $contentDomain['page'],
                $contentDomain['redirect_read'],
                $authDomain['user_read'],
                $themeCatalogFactory(),
                $extensionEditorCatalogFactory(),
                $formDomain['extension_services']
            );

            return $publicChannelController;
        };

        /**
         * Builds the split public category controller on first use.
         */
        $rvn['public_category_controller'] = static function () use (&$publicCategoryController, &$rvn, $publicContentDomain, $themeCatalogFactory): PublicCategoryController {
            if ($publicCategoryController instanceof PublicCategoryController) {
                return $publicCategoryController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $publicCategoryController = new PublicCategoryController(
                $requestContextFactory(),
                $contentDomain['page'],
                $contentDomain['category_lookup'](),
                $themeCatalogFactory()
            );

            return $publicCategoryController;
        };

        /**
         * Builds the split public group controller on first use.
         */
        $rvn['public_group_controller'] = static function () use (&$publicGroupController, &$rvn, $publicAuthDomain): PublicGroupController {
            if ($publicGroupController instanceof PublicGroupController) {
                return $publicGroupController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $authDomain = $publicAuthDomain();
            $publicGroupController = new PublicGroupController(
                $requestContextFactory(),
                $authDomain['group']
            );

            return $publicGroupController;
        };

        /**
         * Builds the split public user controller on first use.
         */
        $rvn['public_user_controller'] = static function () use (&$publicUserController, &$rvn, $publicAuthDomain): PublicUserController {
            if ($publicUserController instanceof PublicUserController) {
                return $publicUserController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $authDomain = $publicAuthDomain();
            $publicUserController = new PublicUserController(
                $requestContextFactory(),
                $authDomain['user_read']
            );

            return $publicUserController;
        };

        /**
         * Builds the split public feed controller on first use.
         */
        $rvn['public_feed_controller'] = static function () use (&$publicFeedController, &$rvn, $publicContentDomain): PublicFeedController {
            if ($publicFeedController instanceof PublicFeedController) {
                return $publicFeedController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $publicFeedController = new PublicFeedController(
                $requestContextFactory(),
                $contentDomain['channel'],
                $contentDomain['page'],
                $contentDomain['category_lookup'](),
                $contentDomain['tag_lookup']()
            );

            return $publicFeedController;
        };

        /**
         * Builds the split public tag controller on first use.
         */
        $rvn['public_tag_controller'] = static function () use (&$publicTagController, &$rvn, $publicContentDomain, $themeCatalogFactory): PublicTagController {
            if ($publicTagController instanceof PublicTagController) {
                return $publicTagController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $publicTagController = new PublicTagController(
                $requestContextFactory(),
                $contentDomain['page'],
                $contentDomain['tag_lookup'](),
                $themeCatalogFactory()
            );

            return $publicTagController;
        };

        /**
         * Builds the split public page controller on first use.
         */
        $rvn['public_page_controller'] = static function () use (&$publicPageController, &$rvn, $publicContentDomain, $publicAuthDomain, $publicFormDomain, $themeCatalogFactory, $extensionEditorCatalogFactory): PublicPageController {
            if ($publicPageController instanceof PublicPageController) {
                return $publicPageController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $authDomain = $publicAuthDomain();
            $formDomain = $publicFormDomain();
            $publicPageController = new PublicPageController(
                $requestContextFactory(),
                $contentDomain['channel'],
                $contentDomain['media'],
                $contentDomain['page'],
                $contentDomain['redirect_read'],
                $authDomain['user_read'],
                $themeCatalogFactory(),
                $extensionEditorCatalogFactory(),
                $formDomain['extension_services']
            );

            return $publicPageController;
        };

        $rvn['public_extension_services'] = $extensionServicesProvider;
    }
}
