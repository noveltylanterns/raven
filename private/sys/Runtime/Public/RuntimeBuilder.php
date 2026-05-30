<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/Public/RuntimeBuilder.php
 * Public runtime assembly on top of the shared core bootstrap.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Runtime\Public;

use Closure;
use PDO;
use Raven\Core\Gatekeeper;
use Raven\Core\Renderer;
use Raven\Core\Repository\ConfigRead;
use Raven\Core\Runtime\Public\ControllerFactory;
use Raven\Core\Runtime\Public\DomainFactory;
use Raven\Core\Runtime\Public\RepoFactory;
use Raven\Core\Runtime\Public\RuntimeInitializer;
use Raven\Lib\Extension\Public\Content as ExtensionContent;
use Raven\Lib\View\Public\ThemeCatalog;
use RuntimeException;

/**
 * Builds public-scope runtime factories on top of the shared Raven container.
 *
 * Keep this builder limited to wiring that is broadly needed across public
 * requests. Route-family-specific policy belongs in public routing registrars
 * and sub-controllers, not in this per-scope runtime builder.
 */
final class RuntimeBuilder
{
    /**
     * Enriches the shared core container with public-runtime factories.
     *
     * @param array<string, mixed> $rvn Shared core bootstrap container.
     * @return array<string, mixed> Public-enriched runtime container.
     */
    public static function build(array $rvn): array
    {
        // Skip public wiring when required bootstrap dependencies are missing.
        if (!isset($rvn['root'], $rvn['db'], $rvn['auth_db'], $rvn['driver'], $rvn['prefix'], $rvn['config'], $rvn['auth'], $rvn['input'], $rvn['csrf'])) {
            return $rvn;
        }

        // These three are captured by reference in memoize closures below so they must be
        // declared here; all other null-init scaffolding from earlier extraction passes has
        // been removed since controller and repo wiring now lives in ControllerFactory and
        // RepoFactory respectively.
        $extensionServices = null;
        $extensionContent = null;
        $themeCatalogService = null;

        $rvn['view'] = new Renderer((string) $rvn['root'] . '/private/tpl/public');
        $categoryEnabled = ConfigRead::bool($rvn['config']->get('category.enabled', false), false);
        $tagEnabled = ConfigRead::bool($rvn['config']->get('tag.enabled', false), false);

        /**
         * Resolves the lazy auth DB handle only for public factories that truly need it.
         */
        $resolveAuthDb = static function () use (&$rvn): PDO {
            $authDb = $rvn['auth_db'] ?? null;
            // Resolve lazy auth DB handle exactly once on first use.
            if (is_callable($authDb)) {
                $authDb = $authDb();
                $rvn['auth_db'] = $authDb;
            }

            // Guard against missing/miswired auth DB resolvers.
            if (!$authDb instanceof PDO) {
                throw new RuntimeException('Public runtime auth database resolver is unavailable.');
            }

            return $authDb;
        };

        /**
         * Resolves the lazy auth service only for public factories that truly need it.
         */
        $resolveAuth = static function () use (&$rvn): Gatekeeper {
            $auth = $rvn['auth'] ?? null;
            // Resolve lazy auth service exactly once on first use.
            if (is_callable($auth)) {
                $auth = $auth();
                $rvn['auth'] = $auth;
            }

            // Guard against missing/miswired auth service resolvers.
            if (!$auth instanceof Gatekeeper) {
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
                // Return memoized instance after first successful build.
                if ($resolved) {
                    return $value;
                }

                $value = $builder();
                $resolved = true;
                return $value;
            };
        };

        /** @var array<string, Closure> $repoFactories */
        $repoFactories = RepoFactory::build($rvn, $memoize, $resolveAuthDb, $categoryEnabled, $tagEnabled);
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
        $categoryReadFactory = $repoFactories['category_lookup'];
        $tagReadFactory = $repoFactories['tag_lookup'];

        /**
         * Boots extension providers only when public runtime code needs extension services.
         *
         * @return array<string, mixed>
         */
        $extensionServicesProvider = static function (?string $extensionDirectory = null) use (&$extensionServices, &$rvn): array {
            $extensionDirectory = is_string($extensionDirectory) ? trim($extensionDirectory) : '';
            // Prefer per-extension provider when caller requests one explicit extension directory.
            if (
                $extensionDirectory !== ''
                && is_callable($rvn['extension_services_for'] ?? null)
            ) {
                /** @var callable(string): array<string, mixed> $extensionServicesFor */
                $extensionServicesFor = $rvn['extension_services_for'];
                return $extensionServicesFor($extensionDirectory);
            }

            // Reuse memoized extension-service map once initialized.
            if (is_array($extensionServices)) {
                return $extensionServices;
            }

            // Fallback to global extension-services provider when available.
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
        $extensionContentFactory = $memoize(static function () use (&$extensionContent, $rvn): ExtensionContent {
            $extensionContent = new ExtensionContent(
                (string) $rvn['root'],
                new \Raven\Lib\Parser\PageBlockParser($rvn['input'])
            );

            return $extensionContent;
        });

        /** @var array<string, Closure> $domainFactories */
        $domainFactories = DomainFactory::build(
            $memoize,
            $channelReadFactory,
            $categoryReadFactory,
            $mediaReadFactory,
            $pageReadFactory,
            $redirectReadFactory,
            $tagReadFactory,
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

        ControllerFactory::register(
            $rvn,
            $resolveAuth,
            $themeCatalogFactory,
            $extensionContentFactory,
            $publicContentDomain,
            $publicAuthDomain,
            $publicFormDomain,
            $extensionServicesProvider
        );

        RuntimeInitializer::register(
            $rvn,
            $publicContentDomain,
            $publicAuthDomain,
            $extensionServicesProvider,
            $categoryEnabled,
            $tagEnabled
        );

        return $rvn;
    }
}
