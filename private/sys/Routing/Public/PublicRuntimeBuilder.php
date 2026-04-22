<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/PublicRuntimeBuilder.php
 * Public runtime assembly on top of the shared core bootstrap.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Closure;
use PDO;
use Raven\Core\Controller\Public\AuthController as PublicAuthController;
use Raven\Core\Controller\Public\CategoryController as PublicCategoryController;
use Raven\Core\Controller\Public\ChannelController as PublicChannelController;
use Raven\Core\Controller\Public\FeedController as PublicFeedController;
use Raven\Core\Controller\Public\GroupController as PublicGroupController;
use Raven\Core\Controller\Public\PageController as PublicPageController;
use Raven\Core\Controller\Public\SharedController;
use Raven\Core\Controller\Public\TagController as PublicTagController;
use Raven\Core\Controller\Public\UserController as PublicUserController;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\GroupRepository;
use Raven\Core\Repository\InviteRepository;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Core\Renderer;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Parser\ChannelDataParser;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Parser\TaxonomyRepoParser;
use RuntimeException;

/**
 * Builds public-scope runtime factories on top of the shared Raven container.
 *
 * Keep this builder limited to wiring that is broadly needed across public
 * requests. Route-family-specific policy belongs in public routing registrars
 * and sub-controllers, not in this per-scope runtime builder.
 */
final class PublicRuntimeBuilder
{
    /**
     * Enriches the shared core container with public-runtime factories.
     *
     * @param array<string, mixed> $rvn Shared core bootstrap container.
     * @return array<string, mixed> Public-enriched runtime container.
     */
    public static function build(array $rvn): array
    {
        if (!isset($rvn['root'], $rvn['db'], $rvn['auth_db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], $rvn['auth'], $rvn['input'], $rvn['csrf'])) {
            return $rvn;
        }

        $publicAuthController = null;
        $publicPageController = null;
        $publicFeedController = null;
        $publicCategoryController = null;
        $publicChannelController = null;
        $publicGroupController = null;
        $publicTagController = null;
        $publicUserController = null;
        $publicSharedController = null;
        $extensionServices = null;
        $inviteTokens = null;
        $taxonomyLookup = null;
        $channelRepository = null;
        $channelDataParser = null;
        $groupRepository = null;
        $pageImageRepository = null;
        $pageRepository = null;
        $redirectRepository = null;
        $userRepository = null;

        $rvn['view'] = new Renderer((string) $rvn['root'] . '/private/tpl');
        $categoryEnabled = ConfigParser::bool($rvn['config']->get('category.enabled', false), false);
        $tagEnabled = ConfigParser::bool($rvn['config']->get('tag.enabled', false), false);

        // Public entry closures ($canRenderPublicProfiler) capture $rvn by value and call
        // $rvn['auth']->method() directly, so auth must be a concrete AuthService before
        // build() returns — resolve both lazy DB and service handles now.
        if (is_callable($rvn['auth_db'])) {
            $rvn['auth_db'] = ($rvn['auth_db'])();
        }
        if (is_callable($rvn['auth'])) {
            $rvn['auth'] = ($rvn['auth'])();
        }

        /**
         * Resolves the lazy auth DB handle only for public factories that truly need it.
         */
        $resolveAuthDb = static function () use (&$rvn): PDO {
            $authDb = $rvn['auth_db'] ?? null;
            if (is_callable($authDb)) {
                $authDb = $authDb();
                $rvn['auth_db'] = $authDb;
            }

            if (!$authDb instanceof PDO) {
                throw new RuntimeException('Public runtime auth database resolver is unavailable.');
            }

            return $authDb;
        };

        /**
         * Resolves the lazy auth service only for public factories that truly need it.
         */
        $resolveAuth = static function () use (&$rvn): AuthService {
            $auth = $rvn['auth'] ?? null;
            if (is_callable($auth)) {
                $auth = $auth();
                $rvn['auth'] = $auth;
            }

            if (!$auth instanceof AuthService) {
                throw new RuntimeException('Public runtime auth service resolver is unavailable.');
            }

            return $auth;
        };

        /**
         * Request-scoped memoization keeps bootstrap factories lightweight while
         * avoiding repeated repo construction within one request.
         *
         * @param callable(): mixed $builder Builder for one runtime value.
         * @return Closure Memoized factory that resolves the value once per request.
         */
        $memoize = static function (callable $builder): Closure {
            $resolved = false;
            $value = null;

            return static function () use (&$resolved, &$value, $builder): mixed {
                if ($resolved) {
                    return $value;
                }

                $value = $builder();
                $resolved = true;
                return $value;
            };
        };

        /**
         * Builds channel storage for public routing/content flows.
         */
        $channelRepositoryFactory = $memoize(static function () use (&$channelRepository, $rvn): ChannelRepository {
            $channelRepository = new ChannelRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                (string) $rvn['root'] . '/private/dat/channel'
            );

            return $channelRepository;
        });

        /**
         * Builds one shared channel parser for public read-side channel lookups.
         */
        $channelDataParserFactory = $memoize(static function () use (&$channelDataParser, $rvn, $channelRepositoryFactory): ChannelDataParser {
            $channelDataParser = new ChannelDataParser(
                $rvn['config'],
                $rvn['input'],
                $channelRepositoryFactory()
            );

            return $channelDataParser;
        });

        /**
         * Builds group storage for public auth/profile flows.
         */
        $groupRepositoryFactory = $memoize(static function () use (&$groupRepository, $rvn): GroupRepository {
            $groupRepository = new GroupRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $groupRepository;
        });

        /**
         * Builds page-image storage only when public rendering needs media rows.
         */
        $pageImageRepositoryFactory = $memoize(static function () use (&$pageImageRepository, $rvn): PageImageRepository {
            $pageImageRepository = new PageImageRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $pageImageRepository;
        });

        /**
         * Builds page storage for public content routes without leaning on shared bootstrap wiring.
         */
        $pageRepositoryFactory = $memoize(static function () use (&$pageRepository, $rvn, $channelRepositoryFactory, $categoryEnabled, $tagEnabled): PageRepository {
            $pageRepository = new PageRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelRepositoryFactory(),
                $categoryEnabled,
                $tagEnabled
            );

            return $pageRepository;
        });

        /**
         * Builds redirect storage for public redirect fallbacks and routing helpers.
         */
        $redirectRepositoryFactory = $memoize(static function () use (&$redirectRepository, $rvn, $channelRepositoryFactory): RedirectRepository {
            $redirectRepository = new RedirectRepository(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelRepositoryFactory()
            );

            return $redirectRepository;
        });

        /**
         * Builds user storage for public login/register/profile flows.
         */
        $userRepositoryFactory = $memoize(static function () use (&$userRepository, $rvn, $resolveAuthDb): UserRepository {
            $userRepository = new UserRepository(
                $resolveAuthDb(),
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $userRepository;
        });

        /**
         * Builds invite-token storage only for the registration flows that need it.
         */
        $inviteTokenRepository = $memoize(static function () use (&$inviteTokens, $rvn, $resolveAuthDb): InviteRepository {
            $inviteTokens = new InviteRepository(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $inviteTokens;
        });

        /**
         * Builds taxonomy lookup parsing only for public routes that actually
         * resolve channel/category/tag slugs.
         */
        $taxonomyLookupRepository = $memoize(static function () use (&$taxonomyLookup, $rvn, $channelRepositoryFactory): TaxonomyRepoParser {
            $taxonomyLookup = new TaxonomyRepoParser(
                $rvn['db'],
                (string) $rvn['driver'],
                (string) $rvn['prefix'],
                $channelRepositoryFactory()
            );

            return $taxonomyLookup;
        });

        /**
         * Boots extension providers only when public runtime code needs extension services.
         *
         * @return array<string, mixed>
         */
        $extensionServicesProvider = static function (?string $extensionDirectory = null) use (&$extensionServices, &$rvn): array {
            $extensionDirectory = is_string($extensionDirectory) ? trim($extensionDirectory) : '';
            if (
                $extensionDirectory !== ''
                && is_callable($rvn['extension_services_for'] ?? null)
            ) {
                /** @var callable(string): array<string, mixed> $extensionServicesFor */
                $extensionServicesFor = $rvn['extension_services_for'];
                return $extensionServicesFor($extensionDirectory);
            }

            if (is_array($extensionServices)) {
                return $extensionServices;
            }

            if (is_callable($rvn['extension_services_all'] ?? null)) {
                /** @var callable(): array<string, array<string, mixed>> $extensionServicesAll */
                $extensionServicesAll = $rvn['extension_services_all'];
                $extensionServices = $extensionServicesAll();
                return $extensionServices;
            }

            /** @var mixed $rawExtensionServices */
            $rawExtensionServices = $rvn['extension_services'] ?? [];
            $extensionServices = is_array($rawExtensionServices) ? $rawExtensionServices : [];
            return $extensionServices;
        };

        /**
         * Public content/feed routes share the same page/channel/redirect runtime
         * dependencies, so expose them as one clustered factory ahead of the later
         * sub-controller split.
         *
         * @return array<string, mixed>
         */
        $publicContentDomain = $memoize(static function () use (
            $channelDataParserFactory,
            $channelRepositoryFactory,
            $pageImageRepositoryFactory,
            $pageRepositoryFactory,
            $redirectRepositoryFactory,
            $taxonomyLookupRepository
        ): array {
            return [
                'channel' => $channelRepositoryFactory(),
                'channel_parser' => $channelDataParserFactory(),
                'page_images' => $pageImageRepositoryFactory(),
                'page' => $pageRepositoryFactory(),
                'redirect' => $redirectRepositoryFactory(),
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
            $groupRepositoryFactory,
            $userRepositoryFactory,
            $inviteTokenRepository
        ): array {
            return [
                'group' => $groupRepositoryFactory(),
                'user' => $userRepositoryFactory(),
                'invite_tokens' => $inviteTokenRepository,
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

        $rvn['public_domain_content'] = $publicContentDomain;
        $rvn['public_domain_channel'] = $publicContentDomain;
        $rvn['public_domain_feed'] = $publicContentDomain;
        $rvn['public_domain_category'] = $publicContentDomain;
        $rvn['public_channel_parser'] = $channelDataParserFactory;
        $rvn['public_domain_auth'] = $publicAuthDomain;
        $rvn['public_domain_profile'] = $publicAuthDomain;
        $rvn['public_domain_group'] = $publicAuthDomain;
        $rvn['public_domain_tag'] = $publicContentDomain;
        $rvn['public_domain_form'] = $publicFormDomain;

        /**
         * Builds the shared request context for split public sub-controllers.
         */
        $rvn['public_request_context'] = static function () use (&$publicSharedController, $rvn, $resolveAuth): SharedController {
            if ($publicSharedController instanceof SharedController) {
                return $publicSharedController;
            }

            $publicSharedController = new SharedController(
                $rvn['config'],
                $resolveAuth(),
                $rvn['input'],
                $rvn['csrf']
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
                $authDomain['user'],
                $authDomain['invite_tokens']
            );

            return $publicAuthController;
        };

        /**
         * Builds the split public channel controller on first use.
         */
        $rvn['public_channel_controller'] = static function () use (&$publicChannelController, &$rvn, $publicContentDomain, $publicAuthDomain, $publicFormDomain): PublicChannelController {
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
                $contentDomain['page_images'],
                $contentDomain['page'],
                $contentDomain['redirect'],
                $authDomain['user'],
                $formDomain['extension_services']
            );

            return $publicChannelController;
        };

        /**
         * Builds the split public category controller on first use.
         */
        $rvn['public_category_controller'] = static function () use (&$publicCategoryController, &$rvn, $publicContentDomain): PublicCategoryController {
            if ($publicCategoryController instanceof PublicCategoryController) {
                return $publicCategoryController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $publicCategoryController = new PublicCategoryController(
                $requestContextFactory(),
                $contentDomain['page'],
                $contentDomain['taxonomy_lookup']()
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
                $authDomain['user']
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
                $contentDomain['channel_parser'],
                $contentDomain['page'],
                $contentDomain['taxonomy_lookup']()
            );

            return $publicFeedController;
        };

        /**
         * Builds the split public tag controller on first use.
         */
        $rvn['public_tag_controller'] = static function () use (&$publicTagController, &$rvn, $publicContentDomain): PublicTagController {
            if ($publicTagController instanceof PublicTagController) {
                return $publicTagController;
            }

            /** @var callable(): SharedController $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $publicTagController = new PublicTagController(
                $requestContextFactory(),
                $contentDomain['page'],
                $contentDomain['taxonomy_lookup']()
            );

            return $publicTagController;
        };

        /**
         * Builds the split public page controller on first use.
         */
        $rvn['public_page_controller'] = static function () use (&$publicPageController, &$rvn, $publicContentDomain, $publicAuthDomain, $publicFormDomain): PublicPageController {
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
                $contentDomain['channel_parser'],
                $contentDomain['page_images'],
                $contentDomain['page'],
                $contentDomain['redirect'],
                $authDomain['user'],
                $formDomain['extension_services']
            );

            return $publicPageController;
        };

        $rvn['public_extension_services'] = $extensionServicesProvider;

        return $rvn;
    }
}
