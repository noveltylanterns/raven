<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Public/ExtensionRouter.php
 * Public extension-route registration.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing\Public;

use Raven\Core\Routing\Router;
use Raven\Lib\Extension\Resolver;
use Raven\Lib\Security\InputSanitizer;

/**
 * Registers extension-provided public routes for enabled module extensions.
 */
final class ExtensionRouter
{
    /**
     * Registers all enabled module extension public routes.
     *
     * @param Router $router Mutable router receiving extension routes.
     * @param array<string, mixed> $rvn Shared runtime container.
     * @param callable(): object $publicRequestContext Lazy public request-context factory.
     * @param InputSanitizer $input Shared input normalizer exposed to extension route files.
     * @return void
     */
    public static function register(
        Router $router,
        array $rvn,
        callable $publicRequestContext,
        InputSanitizer $input
    ): void {
        /** @var array<string, array<string, mixed>> $enabledPublicExtensionManifests */
        $enabledPublicExtensionManifests = is_array($rvn['enabled_extension_manifests'] ?? null)
            ? (array) $rvn['enabled_extension_manifests']
            : [];

        foreach ($enabledPublicExtensionManifests as $extensionName => $manifest) {
            $type = strtolower(trim((string) ($manifest['type'] ?? 'plugin')));
            $isSystemType = $type === 'system' || !empty($manifest['system_extension']);
            if ($isSystemType || $type !== 'module') {
                continue;
            }

            $extensionRoot = $rvn['root'] . '/private/ext/' . $extensionName;
            $routesFile = Resolver::providerPath($extensionRoot, 'routes_public.php');
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
