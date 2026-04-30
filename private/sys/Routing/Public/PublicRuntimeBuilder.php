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
use Raven\Core\Factory\Public\ControllerFactories;
use Raven\Core\Factory\Public\DomainFactories;
use Raven\Core\Factory\Public\RepoFactories;
use Raven\Core\Repository\ChannelRead;
use Raven\Core\Repository\GroupRead;
use Raven\Core\Repository\InviteRead;
use Raven\Core\Repository\InviteWrite;
use Raven\Core\Repository\MediaRead;
use Raven\Core\Repository\PageRead;
use Raven\Core\Repository\RedirectRead;
use Raven\Core\Repository\UserRead;
use Raven\Core\Repository\UserWrite;
use Raven\Core\Renderer;
use Raven\Lib\Auth\AuthService;
use Raven\Lib\Extension\ExtensionEditorCatalogService;
use Raven\Lib\Parser\CategoryRepoParser;
use Raven\Lib\Parser\ConfigParser;
use Raven\Lib\Parser\TagRepoParser;
use Raven\Lib\Parser\TaxonomyRepoParser;
use Raven\Lib\View\Public\ThemeCatalog;
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
        $inviteRead = null;
        $inviteWrite = null;
        $categoryLookup = null;
        $taxonomyLookup = null;
        $tagLookup = null;
        $channelRead = null;
        $extensionEditorCatalogService = null;
        $groupRead = null;
        $mediaRead = null;
        $pageRead = null;
        $redirectRead = null;
        $themeCatalogService = null;
        $userRead = null;
        $userWrite = null;

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

        /** @var array<string, Closure> $repoFactories */
        $repoFactories = RepoFactories::build($rvn, $memoize, $resolveAuthDb, $categoryEnabled, $tagEnabled);
        $channelReadFactory = $repoFactories['channel_read'];
        $groupReadFactory = $repoFactories['group_read'];
        $mediaReadFactory = $repoFactories['media_read'];
        $pageReadFactory = $repoFactories['page_read'];
        $redirectReadFactory = $repoFactories['redirect_read'];
        $userReadFactory = $repoFactories['user_read'];
        $userWriteFactory = $repoFactories['user_write'];
        $inviteReadFactory = $repoFactories['invite_read'];
        $inviteWriteFactory = $repoFactories['invite_write'];
        $taxonomyLookupRepository = $repoFactories['taxonomy_lookup'];
        $categoryLookupRepository = $repoFactories['category_lookup'];
        $tagLookupRepository = $repoFactories['tag_lookup'];

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
         * Reuses one shared public-theme catalog across public controllers.
         */
        $themeCatalogFactory = $memoize(static function () use (&$themeCatalogService, $rvn): ThemeCatalog {
            $themeCatalogService = new ThemeCatalog(
                (string) $rvn['root'] . '/public/theme',
                $rvn['input'],
                ['raven']
            );

            return $themeCatalogService;
        });

        /**
         * Reuses one shared extension editor catalog for public page block reads.
         */
        $extensionEditorCatalogFactory = $memoize(static function () use (&$extensionEditorCatalogService, $rvn): ExtensionEditorCatalogService {
            $extensionEditorCatalogService = new ExtensionEditorCatalogService(
                (string) $rvn['root'],
                $rvn['input'],
                new \Raven\Lib\Parser\PageBlockParser($rvn['input'])
            );

            return $extensionEditorCatalogService;
        });

        /** @var array<string, Closure> $domainFactories */
        $domainFactories = DomainFactories::build(
            $memoize,
            $channelReadFactory,
            $categoryLookupRepository,
            $mediaReadFactory,
            $pageReadFactory,
            $redirectReadFactory,
            $tagLookupRepository,
            $taxonomyLookupRepository,
            $groupReadFactory,
            $userReadFactory,
            $userWriteFactory,
            $inviteReadFactory,
            $inviteWriteFactory,
            $extensionServicesProvider
        );
        $publicContentDomain = $domainFactories['public_domain_content'];
        $publicAuthDomain = $domainFactories['public_domain_auth'];
        $publicFormDomain = $domainFactories['public_domain_form'];

        $rvn['public_domain_content'] = $publicContentDomain;
        $rvn['public_domain_channel'] = $publicContentDomain;
        $rvn['public_domain_feed'] = $publicContentDomain;
        $rvn['public_domain_category'] = $publicContentDomain;
        $rvn['public_domain_auth'] = $publicAuthDomain;
        $rvn['public_domain_profile'] = $publicAuthDomain;
        $rvn['public_domain_group'] = $publicAuthDomain;
        $rvn['public_domain_tag'] = $publicContentDomain;
        $rvn['public_domain_form'] = $publicFormDomain;

        ControllerFactories::register(
            $rvn,
            $resolveAuth,
            $themeCatalogFactory,
            $extensionEditorCatalogFactory,
            $publicContentDomain,
            $publicAuthDomain,
            $publicFormDomain,
            $extensionServicesProvider
        );

        return $rvn;
    }
}
