<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/Panel/DocsRouter.php
 * Panel User Manual route registration for canonical Markdown documentation.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router\Panel;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Raven\Core\Router\RouteHandler;

/**
 * Registers authenticated panel documentation routes for every canonical Markdown page.
 */
final class DocsRouter
{
    /**
     * Registers the User Manual index and one route pair for each canonical document.
     *
     * Both extensionless and `.md` paths are registered so the copied documentation
     * keeps its original relative links while panel users can also use clean URLs.
     *
     * @param RouteHandler $router Mutable router receiving documentation routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelDocsController = $deps->panelDocsController;
        $router->add('GET', '/docs', static function () use ($panelDocsController): void {
            $panelDocsController()->index();
        });

        $projectRoot = (string) ($deps->rvn['root'] ?? '');
        $docsRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docs';
        foreach (self::documentPaths($docsRoot) as $documentPath) {
            $routePath = '/docs/' . $documentPath;
            $extensionlessPath = preg_replace('/\.md$/i', '', $documentPath) ?? $documentPath;

            $router->add('GET', $routePath, static function () use ($panelDocsController, $documentPath): void {
                $panelDocsController()->page($documentPath);
            });
            $router->add('GET', '/docs/' . $extensionlessPath, static function () use ($panelDocsController, $documentPath): void {
                $panelDocsController()->page($documentPath);
            });
        }
    }

    /**
     * Finds canonical Markdown documents below the repository docs root.
     *
     * @param string $docsRoot Absolute canonical docs directory.
     * @return array<int, string> Sorted relative Markdown paths.
     */
    private static function documentPaths(string $docsRoot): array
    {
        if (!is_dir($docsRoot)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = ltrim(str_replace($docsRoot, '', $absolutePath), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            // Screenshots are source assets, not User Manual pages.
            if ($relativePath === 'screenshots' || str_starts_with($relativePath, 'screenshots/')) {
                continue;
            }

            $paths[] = $relativePath;
        }

        sort($paths, SORT_STRING);
        return $paths;
    }
}
