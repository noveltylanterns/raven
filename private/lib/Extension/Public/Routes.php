<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Public/Routes.php
 * Reusable public extension-route registration primitives.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Public;

use Raven\Core\Router\RouteHandler;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers extension-provided public routes for enabled module extensions.
 *
 * Lives in lib/ alongside its panel counterpart (PanelRouteRegistrar) so the
 * extension route-loading contract stays out of the sys/Router scope routers.
 */
final class Routes
{
    /**
     * Registers all enabled module extension public routes onto the shared router.
     *
     * Only module-type extensions (non-system, type === 'module') with a
     * routes_public.php provider file are processed; all others are skipped.
     *
     * @param RouteHandler $router Mutable router receiving extension routes.
     * @param array<string, mixed> $rvn Shared runtime container.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer exposed to extension route files.
     * @return void
     */
    public static function register(
        RouteHandler $router,
        array $rvn,
        callable $publicRequestContext,
        InputSanitizer $input
    ): void {
        /** @var array<string, array<string, mixed>> $enabledPublicExtensionManifests */
        $enabledPublicExtensionManifests = is_array($rvn['enabled_extension_manifests'] ?? null)
            ? (array) $rvn['enabled_extension_manifests']
            : [];

        // Register public routes for each enabled module extension manifest.
        foreach ($enabledPublicExtensionManifests as $extensionName => $manifest) {
            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);

            // Only module-type extensions expose public routes; system/plugin/helper types do not.
            if ($isSystemType || $type !== 'module') {
                continue;
            }

            $extensionRoot = $rvn['root'] . '/private/ext/' . $extensionName;
            $routesFile = Resolver::providerPath($extensionRoot, 'routes_public.php');
            // Skip extensions that do not provide routes_public.php.
            if ($routesFile === null) {
                continue;
            }

            /** @var mixed $registrar */
            $registrar = require $routesFile;
            if (!is_callable($registrar)) {
                continue;
            }

            // Pre-resolve the tpl root so the render helper does not rebuild it every time.
            $extTplRoot = rtrim((string) ($rvn['root'] ?? ''), '/') . '/private/ext/' . $extensionName . '/tpl';

            $registrar($router, [
                'rvn' => $rvn,
                'input' => $input,
                'extensionDirectory' => $extensionName,
                'extensionServices' => is_callable($rvn['public_extension_services'] ?? null)
                    ? $rvn['public_extension_services']
                    : static fn (): array => [],
                'notFound' => static function () use ($publicRequestContext): void {
                    $publicRequestContext()->notFound();
                },
                'renderPublicExtension' => static function (string $template, array $data = [], ?string $layout = 'wrapper') use ($publicRequestContext, $extTplRoot): void {
                    $publicRequestContext()->renderPublicExtensionTemplate($template, $data, $layout, $extTplRoot);
                },
            ]);
        }
    }
}
