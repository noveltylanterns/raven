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
use Raven\Core\Controller\Public\AuthController as PublicAuthController;
use Raven\Core\Controller\Public\ContentController as PublicContentController;
use Raven\Core\Controller\Public\FeedController as PublicFeedController;
use Raven\Core\Controller\Public\FormController as PublicFormController;
use Raven\Core\Controller\Public\ProfileController as PublicProfileController;
use Raven\Core\Controller\Public\RequestContext;
use Raven\Core\Repository\ChannelRepository;
use Raven\Core\Repository\GroupRepository;
use Raven\Core\Repository\InviteTokenRepository;
use Raven\Core\Repository\PageImageRepository;
use Raven\Core\Repository\PageRepository;
use Raven\Core\Repository\RedirectRepository;
use Raven\Core\Repository\TaxonomyLookupRepository;
use Raven\Core\Repository\UserRepository;
use Raven\Core\View;
use Raven\Lib\Config\ConfigValueParser;
use Raven\Lib\Auth\AuthService;
use PDO;
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
        $publicContentController = null;
        $publicFeedController = null;
        $publicFormController = null;
        $publicProfileController = null;
        $publicRequestContext = null;
        $extensionServices = null;
        $inviteTokens = null;
        $taxonomyLookup = null;
        $channelRepository = null;
        $groupRepository = null;
        $pageImageRepository = null;
        $pageRepository = null;
        $redirectRepository = null;
        $userRepository = null;

        $rvn['view'] = new View((string) $rvn['root'] . '/private/tpl');
        $categoryEnabled = ConfigValueParser::bool($rvn['config']->get('category.enabled', false), false);
        $tagEnabled = ConfigValueParser::bool($rvn['config']->get('tag.enabled', false), false);

        // Public entry closures ($canRenderPublicDebugToolbar) capture $rvn by value and call
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
        $inviteTokenRepository = $memoize(static function () use (&$inviteTokens, $rvn, $resolveAuthDb): InviteTokenRepository {
            $inviteTokens = new InviteTokenRepository(
                $resolveAuthDb(),
                (string) $rvn['driver'],
                (string) $rvn['prefix']
            );

            return $inviteTokens;
        });

        /**
         * Builds taxonomy lookup storage only for public routes that actually
         * resolve channel/category/tag slugs.
         */
        $taxonomyLookupRepository = $memoize(static function () use (&$taxonomyLookup, $rvn, $channelRepositoryFactory): TaxonomyLookupRepository {
            $taxonomyLookup = new TaxonomyLookupRepository(
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
            $channelRepositoryFactory,
            $pageImageRepositoryFactory,
            $pageRepositoryFactory,
            $redirectRepositoryFactory,
            $taxonomyLookupRepository
        ): array {
            return [
                'channel' => $channelRepositoryFactory(),
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
        $rvn['public_domain_feed'] = $publicContentDomain;
        $rvn['public_domain_auth'] = $publicAuthDomain;
        $rvn['public_domain_profile'] = $publicAuthDomain;
        $rvn['public_domain_form'] = $publicFormDomain;

        /**
         * Builds the shared request context for split public sub-controllers.
         */
        $rvn['public_request_context'] = static function () use (&$publicRequestContext, $rvn, $resolveAuth): RequestContext {
            if ($publicRequestContext instanceof RequestContext) {
                return $publicRequestContext;
            }

            $publicRequestContext = new RequestContext(
                $rvn['config'],
                $resolveAuth(),
                $rvn['input'],
                $rvn['csrf']
            );

            return $publicRequestContext;
        };

        /**
         * Builds the split public auth controller on first use.
         */
        $rvn['public_auth_controller'] = static function () use (&$publicAuthController, &$rvn, $publicAuthDomain): PublicAuthController {
            if ($publicAuthController instanceof PublicAuthController) {
                return $publicAuthController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
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
         * Builds the split public profile controller on first use.
         */
        $rvn['public_profile_controller'] = static function () use (&$publicProfileController, &$rvn, $publicAuthDomain): PublicProfileController {
            if ($publicProfileController instanceof PublicProfileController) {
                return $publicProfileController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $authDomain = $publicAuthDomain();
            $publicProfileController = new PublicProfileController(
                $requestContextFactory(),
                $authDomain['group'],
                $authDomain['user']
            );

            return $publicProfileController;
        };

        /**
         * Builds the split public form controller on first use.
         */
        $rvn['public_form_controller'] = static function () use (&$publicFormController, &$rvn, $publicFormDomain): PublicFormController {
            if ($publicFormController instanceof PublicFormController) {
                return $publicFormController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $formDomain = $publicFormDomain();
            $publicFormController = new PublicFormController(
                $requestContextFactory(),
                $formDomain['extension_services']
            );

            return $publicFormController;
        };

        /**
         * Builds the split public feed/taxonomy controller on first use.
         */
        $rvn['public_feed_controller'] = static function () use (&$publicFeedController, &$rvn, $publicContentDomain): PublicFeedController {
            if ($publicFeedController instanceof PublicFeedController) {
                return $publicFeedController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $publicFeedController = new PublicFeedController(
                $requestContextFactory(),
                $contentDomain['channel'],
                $contentDomain['page'],
                $contentDomain['taxonomy_lookup']()
            );

            return $publicFeedController;
        };

        /**
         * Builds the split public content controller on first use.
         */
        $rvn['public_content_controller'] = static function () use (&$publicContentController, &$rvn, $publicContentDomain, $publicAuthDomain, $publicFormDomain): PublicContentController {
            if ($publicContentController instanceof PublicContentController) {
                return $publicContentController;
            }

            /** @var callable(): RequestContext $requestContextFactory */
            $requestContextFactory = $rvn['public_request_context'];
            $contentDomain = $publicContentDomain();
            $authDomain = $publicAuthDomain();
            $formDomain = $publicFormDomain();
            $publicContentController = new PublicContentController(
                $requestContextFactory(),
                $contentDomain['channel'],
                $contentDomain['page_images'],
                $contentDomain['page'],
                $contentDomain['redirect'],
                $authDomain['user'],
                $formDomain['extension_services']
            );

            return $publicContentController;
        };

        $rvn['public_extension_services'] = $extensionServicesProvider;

        return $rvn;
    }
}
