<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/DocsController.php
 * Authenticated panel User Manual controller for the shipped Markdown documentation.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Raven\Lib\View\Public\PageMarkdown;

/**
 * Serves the canonical Raven documentation inside the authenticated panel shell.
 */
final class DocsController
{
    private SharedController $context;
    private string $docsRoot;
    private PageMarkdown $markdown;

    /**
     * Creates one panel documentation controller.
     *
     * @param SharedController $context Shared panel request context and wrapper renderer.
     * @param string $projectRoot Absolute Raven project root containing the canonical docs/ tree.
     * @return void
     */
    public function __construct(SharedController $context, string $projectRoot)
    {
        $this->context = $context;
        $this->docsRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docs';
        $this->markdown = new PageMarkdown();
    }

    /**
     * Renders the User Manual index from the canonical documentation readme.
     *
     * @return void
     */
    public function index(): void
    {
        $this->page('readme.md');
    }

    /**
     * Renders one safe Markdown document inside the standard panel wrapper.
     *
     * @param string $relativePath Documentation path from the panel/docs URL namespace, with or without `.md`.
     * @return void
     */
    public function page(string $relativePath): void
    {
        $this->context->requirePanelLogin();
        $documentPath = $this->normalizeDocumentPath($relativePath);
        $documentFile = $documentPath !== null ? $this->resolveDocumentFile($documentPath) : null;
        if ($documentFile === null) {
            $this->context->renderPanelNotFound();
            return;
        }

        $source = file_get_contents($documentFile);
        if (!is_string($source)) {
            $this->context->renderPanelNotFound();
            return;
        }

        $documentTitle = $this->extractTitle($source);
        // The shared header card owns the document H1, so avoid rendering it twice in the body.
        $contentHtml = $this->markdown->toHtml($this->removeLeadingTitle($source));
        if ($contentHtml === '') {
            $this->context->renderPanelNotFound();
            return;
        }

        $this->context->renderPanel('panel/docs', [
            'contentHtml' => $contentHtml,
            'documentPath' => $documentPath,
            'documentTitle' => $documentTitle !== '' ? $documentTitle : 'User Manual',
            'section' => 'docs',
            'csrfField' => $this->context->csrf()->field(),
        ]);
    }

    /**
     * Normalizes one route path and rejects traversal or unsupported document types.
     *
     * @param string $relativePath Raw route-provided document path.
     * @return string|null Safe relative Markdown path, or null when invalid.
     */
    private function normalizeDocumentPath(string $relativePath): ?string
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '' || str_contains($normalized, "\0")) {
            return null;
        }

        if (preg_match('~^[a-z0-9_./-]+$~i', $normalized) !== 1) {
            return null;
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        // Screenshots are source assets and are intentionally excluded from the manual.
        if (($segments[0] ?? '') === 'screenshots') {
            return null;
        }

        if (!str_ends_with(strtolower($normalized), '.md')) {
            $normalized .= '.md';
        }

        return $normalized;
    }

    /**
     * Resolves one normalized document path while enforcing the canonical docs boundary.
     *
     * @param string $documentPath Safe relative Markdown path.
     * @return string|null Real document path, or null when the file is unavailable.
     */
    private function resolveDocumentFile(string $documentPath): ?string
    {
        $rootReal = realpath($this->docsRoot);
        $fileReal = realpath($this->docsRoot . DIRECTORY_SEPARATOR . $documentPath);
        if ($rootReal === false || $fileReal === false || !is_file($fileReal) || !is_readable($fileReal)) {
            return null;
        }

        if ($fileReal !== $rootReal && !str_starts_with($fileReal, $rootReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $fileReal;
    }

    /**
     * Extracts the first Markdown H1 for the shared panel heading card.
     *
     * @param string $source Raw Markdown document source.
     * @return string Plain-text document title, or an empty string when no H1 exists.
     */
    private function extractTitle(string $source): string
    {
        if (preg_match('/^\s*#\s+(.+?)\s*$/m', $source, $matches) !== 1) {
            return '';
        }

        $title = trim(strip_tags($this->markdown->renderInline((string) ($matches[1] ?? ''))));
        return preg_replace('/\s+/', ' ', $title) ?? $title;
    }

    /**
     * Removes the first Markdown H1 when it appears at the beginning of a document.
     *
     * @param string $source Raw Markdown document source.
     * @return string Markdown source without its leading H1, when present.
     */
    private function removeLeadingTitle(string $source): string
    {
        $withoutTitle = preg_replace('/\A\s*#\s+.+?(?:\r?\n|$)/', '', $source, 1);
        return is_string($withoutTitle) ? ltrim($withoutTitle) : $source;
    }
}
