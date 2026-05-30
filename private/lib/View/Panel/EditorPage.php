<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorPage.php
 * Panel page body-block helpers for editor submissions and block menus.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Parser\PageBlockParser;
use Raven\Lib\Security\InputSanitizer;

/**
 * Panel-only page body-block helper for editor payload normalization.
 */
final class EditorPage
{
    private InputSanitizer $input;
    private PageBlockParser $pageBlockParser;

    /**
     * Initializes the panel page-block helper.
     *
     * @param InputSanitizer $input Shared sanitizer for editor textarea/path payloads.
     * @param PageBlockParser|null $pageBlockParser Shared page-block parser for type and CSS normalization.
     * @return void
     */
    public function __construct(InputSanitizer $input, ?PageBlockParser $pageBlockParser = null)
    {
        $this->input = $input;
        $this->pageBlockParser = $pageBlockParser ?? new PageBlockParser($input);
    }

    /**
     * Merges core and extension body-block definitions for the page editor.
     *
     * @param array<string, array{label: string, editor: string}> $extensionDefinitions Extension-provided block definitions.
     * @return array<string, array{label: string, editor: string}> Complete editor definition map.
     */
    public function mergeTypeDefinitions(array $extensionDefinitions = []): array
    {
        $definitions = $this->pageBlockParser->defaultDefinitions();
        // Allow extensions to append only unknown types so core definitions remain canonical.
        foreach ($extensionDefinitions as $type => $definition) {
            // Core block types keep ownership of their labels and editor modes.
            if (isset($definitions[$type])) {
                continue;
            }

            $definitions[$type] = $definition;
        }

        return $definitions;
    }

    /**
     * Normalizes a raw page-editor POST payload into persistable block rows.
     *
     * @param mixed $raw Raw `content_blocks` editor payload.
     * @param array<string, array{label: string, editor: string}> $definitions Complete body-block definition map.
     * @param int $maxBlocks Maximum number of blocks to preserve from the editor payload.
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}> Normalized block rows ready for page persistence.
     */
    public function normalizeEditorSubmittedBlocks(mixed $raw, array $definitions, int $maxBlocks = 50): array
    {
        // Reject malformed payloads so downstream persistence always receives list-shaped data.
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        // Normalize each submitted row while preserving original editor order.
        foreach ($raw as $entry) {
            // Enforce configured cap to protect persistence and render paths from oversized submissions.
            if ($maxBlocks > 0 && count($blocks) >= $maxBlocks) {
                break;
            }

            $type = 'tinymce';
            $value = $entry;
            $cssId = '';
            $cssClass = '';
            // Array rows can carry explicit type/content/css fields from advanced block editors.
            if (is_array($entry)) {
                $type = $this->pageBlockParser->normalizeType((string) ($entry['type'] ?? 'tinymce'), $definitions);
                $value = $entry['content'] ?? '';
                $cssId = $this->pageBlockParser->normalizeCssId($entry['css_id'] ?? null);
                $cssClass = $this->pageBlockParser->normalizeCssClassList($entry['css_class'] ?? null);
            }

            // Ignore non-scalar content payloads that cannot be sanitized to editor text/html.
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $editorMode = $this->pageBlockParser->editorMode($type, $definitions);
            $normalized = $this->input->html($value !== null ? (string) $value : null, 500000);
            // File-backed markdown blocks store path-like content and should not preserve outer whitespace.
            if ($editorMode === 'markdown_file') {
                $normalized = trim($normalized);
            }

            // Gallery blocks carry structure only; content is persisted separately in media tables.
            if ($editorMode === 'gallery') {
                $blocks[] = [
                    'type' => $type,
                    'content' => '',
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

            // Drop empty textual blocks after normalization to avoid storing presentation-only rows.
            if (trim($normalized) === '') {
                continue;
            }

            $blocks[] = [
                'type' => $type,
                'content' => $normalized,
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
        }

        return $blocks;
    }

    /**
     * Returns true when the normalized block list contains a gallery block.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks Normalized editor block rows.
     * @param array<string, array{label: string, editor: string}> $definitions Complete body-block definition map.
     * @return bool True when at least one block renders the page gallery.
     */
    public function hasGalleryBlock(array $blocks, array $definitions): bool
    {
        return $this->pageBlockParser->hasGalleryBlock(
            $blocks,
            fn (string $type): string => $this->pageBlockParser->editorMode($type, $definitions)
        );
    }
}
