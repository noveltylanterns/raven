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
use Raven\Lib\Parser\PanelParser;
use Raven\Lib\Transport\Redirect;

/**
 * Registers authenticated panel documentation routes for every canonical Markdown page.
 */
final class DocsRouter
{
    /**
     * Registers the User Manual home route and one route pair for each canonical document.
     *
     * Extensionless paths are canonical; `.md` aliases are registered only for
     * enumerated documents and permanently redirect to their clean counterparts.
     *
     * @param RouteHandler $router Mutable router receiving documentation routes.
     * @param PanelPayload $deps Shared panel route dependency payload.
     * @return void
     */
    public static function registerWithDeps(RouteHandler $router, PanelPayload $deps): void
    {
        $panelDocsController = $deps->panelDocsController;
        $canonicalHomePath = PanelParser::fromConfig($deps->rvn['config'], '/docs/home');
        $router->add('GET', '/docs', static function () use ($canonicalHomePath): void {
            // Keep the legacy panel/docs entrypoint as a stable redirect to the canonical home URL.
            Redirect::redirect($canonicalHomePath, 301);
        });
        $router->add('GET', '/docs/home', static function () use ($panelDocsController): void {
            $panelDocsController()->index();
        });
        $canonicalAppendixHomePath = PanelParser::fromConfig($deps->rvn['config'], '/docs/appendix/home');
        $router->add('GET', '/docs/appendix', static function () use ($canonicalAppendixHomePath): void {
            // Keep the directory entrypoint stable while giving the appendix one canonical home URL.
            Redirect::redirect($canonicalAppendixHomePath, 301);
        });
        $router->add('GET', '/docs/appendix/home', static function () use ($panelDocsController): void {
            $panelDocsController()->page('appendix/readme.md');
        });
        $router->add('GET', '/docs/appendix/readme', static function () use ($canonicalAppendixHomePath): void {
            // The source filename is an implementation detail, not a second public appendix index.
            Redirect::redirect($canonicalAppendixHomePath, 301);
        });

        $projectRoot = (string) ($deps->rvn['root'] ?? '');
        $docsRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docs';
        foreach (self::documentPaths($docsRoot) as $documentPath) {
            $routePath = '/docs/' . $documentPath;
            $extensionlessPath = preg_replace('/\.md$/i', '', $documentPath) ?? $documentPath;

            $canonicalPath = $extensionlessPath === 'appendix/readme'
                ? $canonicalAppendixHomePath
                : PanelParser::fromConfig($deps->rvn['config'], '/docs/' . $extensionlessPath);
            $router->add('GET', $routePath, static function () use ($canonicalPath): void {
                // Redirect only a known Markdown document alias; arbitrary panel paths never enter this route family.
                Redirect::redirect($canonicalPath, 301);
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
