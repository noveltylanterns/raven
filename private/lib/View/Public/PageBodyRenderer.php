<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/PageBodyRenderer.php
 * Public page body-block rendering helpers for normalized editor modes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Renders public page body-block content by normalized editor mode.
 */
final class PageBodyRenderer
{
    private string $projectRoot;
    private MarkdownRenderer $markdown;

    /**
     * @param string $projectRoot Absolute project root used to resolve local Markdown-file blocks safely.
     * @param MarkdownRenderer $markdown Shared Markdown renderer for Markdown body blocks.
     * @return void
     */
    public function __construct(string $projectRoot, MarkdownRenderer $markdown)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->markdown = $markdown;
    }

    /**
     * Renders one page body block using the configured editor mode.
     *
     * @param string $editorMode Normalized editor-mode slug for the block payload.
     * @param string $content Raw stored block content before rendering.
     * @param callable(string): string $renderEmbeddedForms Callback that expands embedded forms/shortcodes in rendered HTML.
     * @return string Rendered HTML for the block, or an empty string when the mode yields no output.
     */
    public function renderByEditorMode(string $editorMode, string $content, callable $renderEmbeddedForms): string
    {
        $content = str_replace("\0", '', $content);

        return match ($editorMode) {
            // Plaintext blocks use a raw textarea in the editor; their content is authored HTML
            // and must render the same way TinyMCE blocks do — not escaped to literal text.
            'plaintext' => $renderEmbeddedForms($content),
            'autobr' => '<div class="raven-page-body-autobr">'
                . $this->escapeNewlinesAsBreaks($content)
                . '</div>',
            'markdown' => $this->renderMarkdownBlockContent($content, $renderEmbeddedForms),
            'markdown_file' => $this->renderMarkdownFileBlock($content, $renderEmbeddedForms),
            'gallery' => '',
            default => $renderEmbeddedForms($content),
        };
    }

    /**
     * Renders one inline Markdown block before embedded-form expansion.
     *
     * @param string $markdown Raw Markdown block content.
     * @param callable(string): string $renderEmbeddedForms Callback that expands embedded forms/shortcodes in rendered HTML.
     * @return string Rendered HTML for the Markdown block, or an empty string when nothing remains.
     */
    private function renderMarkdownBlockContent(string $markdown, callable $renderEmbeddedForms): string
    {
        $html = $this->markdown->toHtml($markdown);
        if (trim($html) === '') {
            return '';
        }

        return $renderEmbeddedForms($html);
    }

    /**
     * Renders one Markdown-file body block from a safe local project path.
     *
     * @param string $pathInput User-authored local Markdown path from the block payload.
     * @param callable(string): string $renderEmbeddedForms Callback that expands embedded forms/shortcodes in rendered HTML.
     * @return string Rendered HTML for the Markdown file, or an empty string when the file is unusable.
     */
    private function renderMarkdownFileBlock(string $pathInput, callable $renderEmbeddedForms): string
    {
        $markdown = $this->loadLocalMarkdownFileForBlock($pathInput);
        if ($markdown === null) {
            return '';
        }

        return $this->renderMarkdownBlockContent($markdown, $renderEmbeddedForms);
    }

    /**
     * Loads one Markdown file referenced by a page body block when it resolves under the project root.
     *
     * @param string $pathInput User-authored local Markdown path from the block payload.
     * @return string|null File contents when the path is safe and readable, or null when rejected.
     */
    private function loadLocalMarkdownFileForBlock(string $pathInput): ?string
    {
        $path = trim($pathInput);
        if ($path === '') {
            return null;
        }

        $path = (string) preg_replace('/[?#].*$/', '', $path);
        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        if (preg_match('/\.(?:md|markdown)$/i', $path) !== 1) {
            return null;
        }

        $projectRootReal = realpath($this->projectRoot);
        if (!is_string($projectRootReal) || $projectRootReal === '') {
            return null;
        }

        // Realpath-based prefix enforcement keeps Markdown-file blocks inside the
        // project tree even when authors attempt traversal or symlink escapes.
        $projectRootPrefix = rtrim($projectRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $trimmedPath = trim($path);
        if ($trimmedPath === '') {
            return null;
        }

        $candidatePath = $projectRootReal . '/' . ltrim($trimmedPath, '/');
        if ($candidatePath === '') {
            return null;
        }

        $resolved = realpath($candidatePath);
        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        if (!str_starts_with($resolved, $projectRootPrefix) || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $content = @file_get_contents($resolved, false, null, 0, 1048576);
        if (!is_string($content) || $content === '') {
            return null;
        }

        return str_replace("\0", '', $content);
    }

    /**
     * Escapes one string for safe inline HTML output.
     *
     * @param string $value Raw text fragment.
     * @return string HTML-escaped text.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escapes plain text and converts normalized newlines into `<br>` tags.
     *
     * @param string $value Raw multiline text.
     * @return string Escaped HTML with `<br>` separators.
     */
    private function escapeNewlinesAsBreaks(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        return nl2br($this->escapeHtml($normalized), false);
    }
}
