<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Renders public page body-block content by normalized editor mode.
 */
final class PublicPageBodyRenderer
{
    private string $projectRoot;
    private MarkdownRenderer $markdown;

    public function __construct(string $projectRoot, MarkdownRenderer $markdown)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->markdown = $markdown;
    }

    /**
     * @param callable(string): string $renderEmbeddedForms
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
     * @param callable(string): string $renderEmbeddedForms
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
     * @param callable(string): string $renderEmbeddedForms
     */
    private function renderMarkdownFileBlock(string $pathInput, callable $renderEmbeddedForms): string
    {
        $markdown = $this->loadLocalMarkdownFileForBlock($pathInput);
        if ($markdown === null) {
            return '';
        }

        return $this->renderMarkdownBlockContent($markdown, $renderEmbeddedForms);
    }

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

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeNewlinesAsBreaks(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        return nl2br($this->escapeHtml($normalized), false);
    }
}

