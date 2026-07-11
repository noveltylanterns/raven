<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/PageBlocks.php
 * Public page body-block rendering helpers for normalized public output.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use Closure;
use Raven\Lib\Extension\Public\MarkdownFileLoader;
use Raven\Lib\Parser\PageBlockParser;

/**
 * Public-only page body-block helper for rendered page output.
 */
final class PageBlocks
{
    private string $projectRoot;
    private PageBlockParser $pageBlockParser;
    private PageMarkdown $pageMarkdown;
    private ?Closure $extensionServicesProvider;

    /**
     * Initializes the public page-block helper.
     *
     * @param string $projectRoot Absolute project root used to resolve local Markdown-file blocks safely.
     * @param PageBlockParser $pageBlockParser Shared page-block parser for type and CSS normalization.
     * @param PageMarkdown $pageMarkdown Shared Markdown renderer for Markdown body blocks.
     * @param callable(?string=): array<string, mixed>|null $extensionServicesProvider Optional lazy extension-service resolver for external Markdown sources.
     * @return void
     */
    public function __construct(
        string $projectRoot,
        PageBlockParser $pageBlockParser,
        PageMarkdown $pageMarkdown,
        ?callable $extensionServicesProvider = null
    )
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->pageBlockParser = $pageBlockParser;
        $this->pageMarkdown = $pageMarkdown;
        $this->extensionServicesProvider = $extensionServicesProvider !== null
            ? Closure::fromCallable($extensionServicesProvider)
            : null;
    }

    /**
     * Merges core and extension body-block definitions for public rendering.
     *
     * @param array<string, array{label: string, editor: string}> $extensionDefinitions Extension-provided block definitions.
     * @return array<string, array{label: string, editor: string}> Complete public block definition map.
     */
    public function mergeTypeDefinitions(array $extensionDefinitions = []): array
    {
        $definitions = $this->pageBlockParser->defaultDefinitions();
        // Extension definitions may add new block types but cannot override core ones.
        foreach ($extensionDefinitions as $type => $definition) {
            // Keep canonical core definitions when type keys collide.
            if (isset($definitions[$type])) {
                continue;
            }

            $definitions[$type] = $definition;
        }

        return $definitions;
    }

    /**
     * Renders normalized public block payloads into template-facing HTML rows.
     *
     * @param array<string, mixed> $page Public page payload containing `content_blocks`.
     * @param array<string, array{label: string, editor: string}> $definitions Complete body-block definition map.
     * @param callable(): string $galleryRenderer Callback that renders the page gallery block lazily.
     * @param callable(string): string $embeddedFormRenderer Callback that expands embedded forms/shortcodes in block HTML.
     * @return array<string, mixed> Public page payload with rendered `content_blocks`.
     */
    public function renderPageContentBlocks(
        array $page,
        array $definitions,
        callable $galleryRenderer,
        callable $embeddedFormRenderer
    ): array {
        $rawBlocks = $page['content_blocks'] ?? null;
        // Coerce malformed content payloads to an empty block list.
        if (!is_array($rawBlocks)) {
            $rawBlocks = [];
        }

        $renderedBlocks = [];
        $galleryBlockHtml = null;
        // Render each submitted block independently in declared order.
        foreach ($rawBlocks as $block) {
            $type = 'tinymce';
            $content = '';
            $cssId = '';
            $cssClass = '';

            // Structured block arrays carry explicit type/content/css metadata.
            if (is_array($block)) {
                $type = $this->pageBlockParser->normalizeType((string) ($block['type'] ?? 'tinymce'), $definitions);
                $value = $block['content'] ?? '';
                $cssId = $this->pageBlockParser->normalizeCssId($block['css_id'] ?? null);
                $cssClass = $this->pageBlockParser->normalizeCssClassList($block['css_class'] ?? null);
                // Skip non-scalar content values that cannot be rendered safely.
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }

                $content = (string) ($value ?? '');
            } else {
                // Scalar shorthand blocks are allowed for backwards compatibility payloads.
                if (!is_scalar($block) && $block !== null) {
                    continue;
                }

                $content = (string) ($block ?? '');
            }

            // Gallery block HTML is rendered lazily once and reused for duplicate gallery blocks.
            if ($this->pageBlockParser->editorMode($type, $definitions) === 'gallery') {
                // Defer gallery rendering until the first gallery block is encountered.
                if (!is_string($galleryBlockHtml)) {
                    $galleryBlockHtml = (string) $galleryRenderer();
                }
                // Skip gallery blocks when renderer produced empty output.
                if (trim($galleryBlockHtml) === '') {
                    continue;
                }

                $renderedBlocks[] = [
                    'html' => $galleryBlockHtml,
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

            $html = $this->renderBlockHtml($type, $content, $definitions, $embeddedFormRenderer);
            // Drop blocks that render to empty output after mode-specific processing.
            if (trim($html) === '') {
                continue;
            }

            $renderedBlocks[] = [
                'html' => $html,
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
        }

        $page['content_blocks'] = $renderedBlocks;
        return $page;
    }

    /**
     * Renders one non-gallery public page block into HTML.
     *
     * @param string $type Normalized body-block type key.
     * @param string $content Raw stored body-block content.
     * @param array<string, array{label: string, editor: string}> $definitions Complete body-block definition map.
     * @param callable(string): string $embeddedFormRenderer Callback that expands embedded forms/shortcodes in block HTML.
     * @return string Rendered block HTML.
     */
    private function renderBlockHtml(
        string $type,
        string $content,
        array $definitions,
        callable $embeddedFormRenderer
    ): string {
        $content = str_replace("\0", '', $content);
        $editorMode = $this->pageBlockParser->editorMode($this->pageBlockParser->normalizeType($type, $definitions), $definitions);

        return match ($editorMode) {
            // Plaintext blocks use a raw textarea in the editor; their content is authored HTML
            // and must render the same way TinyMCE blocks do instead of escaping to literal text.
            'plaintext' => $embeddedFormRenderer($content),
            'autobr' => '<div class="raven-page-body-autobr">' . $this->escapeNewlinesAsBreaks($content) . '</div>',
            'markdown' => $this->renderMarkdownBlockContent($content, $embeddedFormRenderer),
            'markdown_file' => $this->renderMarkdownFileBlock($content, $embeddedFormRenderer),
            'gallery' => '',
            default => $embeddedFormRenderer($content),
        };
    }

    /**
     * Renders one inline Markdown block before embedded-form expansion.
     *
     * @param string $markdown Raw Markdown block content.
     * @param callable(string): string $embeddedFormRenderer Callback that expands embedded forms/shortcodes in rendered HTML.
     * @return string Rendered HTML for the Markdown block, or an empty string when nothing remains.
     */
    private function renderMarkdownBlockContent(string $markdown, callable $embeddedFormRenderer): string
    {
        $html = $this->pageMarkdown->toHtml($markdown);
        // Empty Markdown render results should not emit wrapper artifacts.
        if (trim($html) === '') {
            return '';
        }

        return $embeddedFormRenderer($html);
    }

    /**
     * Renders one Markdown-file body block from a safe local or extension-provided path.
     *
     * @param string $pathInput User-authored local or extension-backed Markdown path from the block payload.
     * @param callable(string): string $embeddedFormRenderer Callback that expands embedded forms/shortcodes in rendered HTML.
     * @return string Rendered HTML for the Markdown file, or an empty string when the file is unusable.
     */
    private function renderMarkdownFileBlock(string $pathInput, callable $embeddedFormRenderer): string
    {
        $markdown = $this->loadMarkdownFileForBlock($pathInput);
        // Abort file-backed block rendering when safe file load failed.
        if ($markdown === null) {
            return '';
        }

        return $this->renderMarkdownBlockContent($markdown, $embeddedFormRenderer);
    }

    /**
     * Loads one Markdown source through an extension loader before falling back to local storage.
     *
     * @param string $pathInput User-authored local or extension-backed Markdown path.
     * @return string|null File contents when a source is safe and readable, or null when rejected.
     */
    private function loadMarkdownFileForBlock(string $pathInput): ?string
    {
        $external = $this->loadExternalMarkdownFileForBlock($pathInput);
        if ($external !== null) {
            return $external;
        }

        return $this->loadLocalMarkdownFileForBlock($pathInput);
    }

    /**
     * Loads a URI-like Markdown source through registered extension contracts.
     *
     * @param string $pathInput User-authored Markdown source reference.
     * @return string|null Loaded Markdown contents, or null when no loader accepts the reference.
     */
    private function loadExternalMarkdownFileForBlock(string $pathInput): ?string
    {
        $reference = trim($pathInput);
        // Local project paths do not need to boot extension services.
        if (
            $this->extensionServicesProvider === null
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $reference) !== 1
        ) {
            return null;
        }

        try {
            $services = ($this->extensionServicesProvider)();
        } catch (\Throwable) {
            // An unavailable optional extension must not break ordinary page rendering.
            return null;
        }

        if (!is_array($services)) {
            return null;
        }

        // Each extension may expose one or more objects under the shared loader contract.
        foreach ($services as $serviceBucket) {
            if (!is_array($serviceBucket)) {
                continue;
            }

            $loaders = $serviceBucket['markdown_file_loaders'] ?? [];
            if ($loaders instanceof MarkdownFileLoader) {
                $loaders = [$loaders];
            }
            if (!is_array($loaders)) {
                continue;
            }

            foreach ($loaders as $loader) {
                if (!$loader instanceof MarkdownFileLoader) {
                    continue;
                }

                try {
                    $content = $loader->load($reference);
                } catch (\Throwable) {
                    // One unavailable loader should not prevent other providers from trying.
                    continue;
                }

                if (is_string($content)) {
                    return str_replace("\0", '', $content);
                }
            }
        }

        return null;
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
        // Empty paths cannot reference a Markdown file.
        if ($path === '') {
            return null;
        }

        $path = (string) preg_replace('/[?#].*$/', '', $path);
        // Remove query/fragment suffixes and reject blanks after trimming.
        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        // Only markdown-like extensions are allowed for file-backed block paths.
        if (preg_match('/\.(?:md|markdown)$/i', $path) !== 1) {
            return null;
        }

        $projectRootReal = realpath($this->projectRoot);
        // Abort when project root cannot be resolved on disk.
        if (!is_string($projectRootReal) || $projectRootReal === '') {
            return null;
        }

        // Realpath-based prefix enforcement keeps Markdown-file blocks inside the
        // project tree even when authors attempt traversal or symlink escapes.
        $projectRootPrefix = rtrim($projectRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $trimmedPath = trim($path);
        // Path is re-trimmed after normalization to reject whitespace-only variants.
        if ($trimmedPath === '') {
            return null;
        }

        $candidatePath = $projectRootReal . '/' . ltrim($trimmedPath, '/');
        // Defensive guard for unexpected empty candidate paths.
        if ($candidatePath === '') {
            return null;
        }

        $resolved = realpath($candidatePath);
        // Reject unresolved files before any prefix/readability checks.
        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        // Enforce project-root scope and readable regular-file requirements.
        if (!str_starts_with($resolved, $projectRootPrefix) || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $content = @file_get_contents($resolved, false, null, 0, 1048576);
        // Empty or failed reads are treated as unusable markdown sources.
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
