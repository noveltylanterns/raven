<?php

/**
 * RAVEN CMS
 * ~/private/lib/Parser/PageBlockParser.php
 * Shared page body-block parsing and normalization helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Parser;

use Raven\Lib\Security\InputSanitizer;

/**
 * Canonical page body-block parser for shared type and payload normalization.
 */
final class PageBlockParser
{
    private InputSanitizer $input;

    /**
     * Initializes the shared page-block parser.
     *
     * @param InputSanitizer|null $input Optional sanitizer used for extension fields and CSS token validation.
     * @return void
     */
    public function __construct(?InputSanitizer $input = null)
    {
        $this->input = $input ?? new InputSanitizer();
    }

    /**
     * Returns the stock body-block definitions available without extensions.
     *
     * @return array<string, array{label: string, editor: string}> Built-in block definitions keyed by type slug.
     */
    public function defaultDefinitions(): array
    {
        return [
            'tinymce' => ['label' => 'Rich Text', 'editor' => 'tinymce'],
            'plaintext' => ['label' => 'Code', 'editor' => 'plaintext'],
            'autobr' => ['label' => 'Plaintext', 'editor' => 'autobr'],
            'markdown' => ['label' => 'Markdown', 'editor' => 'markdown'],
            'markdown_file' => ['label' => 'Markdown File', 'editor' => 'markdown_file'],
            'image_gallery' => ['label' => 'Image Gallery', 'editor' => 'gallery'],
        ];
    }

    /**
     * Normalizes one block type against a known definition map.
     *
     * @param string $value Raw block type value.
     * @param array<string, array{label: string, editor: string}> $definitions Known block definitions keyed by type slug.
     * @return string Normalized type key, or `tinymce` when unknown.
     */
    public function normalizeType(string $value, array $definitions): string
    {
        $type = strtolower(trim($value));
        if ($type === '') {
            return 'tinymce';
        }

        return array_key_exists($type, $definitions) ? $type : 'tinymce';
    }

    /**
     * Resolves the editor mode for one normalized block type.
     *
     * @param string $type Normalized block type.
     * @param array<string, array{label: string, editor: string}> $definitions Known block definitions keyed by type slug.
     * @return string Editor mode key used by the panel and public renderers.
     */
    public function editorMode(string $type, array $definitions): string
    {
        $normalized = strtolower(trim($type));
        if ($normalized === '') {
            return 'tinymce';
        }

        $editor = strtolower(trim((string) ($definitions[$normalized]['editor'] ?? 'tinymce')));
        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file', 'gallery'], true)
            ? $editor
            : 'tinymce';
    }

    /**
     * Normalizes one extension field list into page body-block definition entries.
     *
     * @param string $extensionName Extension slug contributing custom block fields.
     * @param array<int, mixed> $fields Raw extension field definitions.
     * @param array<string, array{label: string, editor: string}> $existing Existing definition map to append to.
     * @return array<string, array{label: string, editor: string}> Normalized definition map with extension entries merged in.
     */
    public function normalizeExtensionDefinitions(string $extensionName, array $fields, array $existing = []): array
    {
        $definitions = $existing;

        foreach ($fields as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $slug = $this->input->slug((string) ($entry['slug'] ?? ''));
            if ($slug === null || $slug === '') {
                continue;
            }

            $normalizedSlug = str_replace('-', '_', strtolower($slug));
            $normalizedExtension = str_replace('-', '_', strtolower($extensionName));
            $type = 'content_' . $normalizedExtension . '_' . $normalizedSlug;
            if (isset($definitions[$type]) || preg_match('/^[a-z0-9_]{1,120}$/', $type) !== 1) {
                continue;
            }

            $label = $this->input->text((string) ($entry['label'] ?? ''), 120);
            $editor = strtolower(trim((string) ($entry['editor'] ?? 'tinymce')));
            if ($label === '' || !in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file'], true)) {
                continue;
            }

            $definitions[$type] = [
                'label' => $label,
                'editor' => $editor,
            ];
        }

        return $definitions;
    }

    /**
     * Normalizes a persisted page-block type from storage.
     *
     * @param string $value Raw stored block type.
     * @return string Supported stored type key, or `tinymce` when invalid.
     */
    public function normalizePersistedType(string $value): string
    {
        $type = strtolower(trim($value));
        if (in_array($type, ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file', 'image_gallery'], true)) {
            return $type;
        }

        return preg_match('/^content_[a-z0-9_]{1,120}$/', $type) === 1
            ? $type
            : 'tinymce';
    }

    /**
     * Normalizes one optional block CSS id token.
     *
     * @param mixed $value Raw CSS id input from storage or the editor.
     * @return string Sanitized CSS id, or an empty string when invalid.
     */
    public function normalizeCssId(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $id = str_replace("\0", '', trim((string) ($value ?? '')));
        $id = ltrim($id, '#');
        if ($id === '') {
            return '';
        }

        if (mb_strlen($id) > 120) {
            $id = mb_substr($id, 0, 120);
        }

        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $id) === 1
            ? $id
            : '';
    }

    /**
     * Normalizes one optional block CSS class list.
     *
     * @param mixed $value Raw CSS class input from storage or the editor.
     * @return string Sanitized space-delimited CSS classes, or an empty string when invalid.
     */
    public function normalizeCssClassList(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $raw = str_replace("\0", '', trim((string) ($value ?? '')));
        if ($raw === '') {
            return '';
        }

        $classMap = [];
        $classes = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $token) {
            $token = ltrim(trim((string) $token), '.');
            if ($token === '' || preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $token) !== 1) {
                continue;
            }

            // Case-insensitive dedupe prevents duplicate classes from bouncing
            // between storage and template rendering with only case changes.
            $key = strtolower($token);
            if (isset($classMap[$key])) {
                continue;
            }

            $classMap[$key] = true;
            $classes[] = $token;
            if (count($classes) >= 12) {
                break;
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Normalizes one stored block array payload into typed rows.
     *
     * @param mixed $raw Raw array payload from repository/controller code.
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}> Normalized block rows ready for storage.
     */
    public function normalizeStoredBlocks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $entry) {
            $normalized = $this->normalizeStoredBlockEntry($entry);
            if ($normalized === null) {
                continue;
            }

            $blocks[] = $normalized;
        }

        return $blocks;
    }

    /**
     * Decodes one stored JSON content payload into normalized block rows.
     *
     * @param string $raw Raw stored content value from the page record.
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}> Normalized block rows.
     */
    public function decodeStoredBlocks(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            // Legacy single-string page bodies remain readable so older content
            // does not break when block storage is introduced or re-saved.
            return [[
                'type' => 'tinymce',
                'content' => $raw,
                'css_id' => '',
                'css_class' => '',
            ]];
        }

        return $this->normalizeStoredBlocks($decoded);
    }

    /**
     * Encodes normalized block rows for page-record persistence.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks Normalized block rows.
     * @return string JSON payload for the page `content` column, or an empty string when nothing is stored.
     */
    public function encodeStoredBlocks(array $blocks): string
    {
        if ($blocks === []) {
            return '';
        }

        $encoded = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Returns true when a normalized block list contains a gallery block.
     *
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks Normalized block rows.
     * @param callable(string): string $editorModeResolver Callback that resolves a block type into its editor mode.
     * @return bool True when at least one block resolves to gallery mode.
     */
    public function hasGalleryBlock(array $blocks, callable $editorModeResolver): bool
    {
        foreach ($blocks as $block) {
            if ((string) $editorModeResolver((string) ($block['type'] ?? '')) === 'gallery') {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes one stored block entry into the canonical array shape.
     *
     * @param mixed $entry Raw block entry from storage or decoded JSON.
     * @return array{type: string, content: string, css_id: string, css_class: string}|null Normalized block row, or null when unusable.
     */
    private function normalizeStoredBlockEntry(mixed $entry): ?array
    {
        $type = 'tinymce';
        $content = '';
        $cssId = '';
        $cssClass = '';

        if (is_array($entry)) {
            $type = $this->normalizePersistedType((string) ($entry['type'] ?? 'tinymce'));
            $value = $entry['content'] ?? '';
            $cssId = $this->normalizeCssId($entry['css_id'] ?? null);
            $cssClass = $this->normalizeCssClassList($entry['css_class'] ?? null);
            if (!is_scalar($value) && $value !== null) {
                return null;
            }

            $content = str_replace("\0", '', (string) ($value ?? ''));
        } else {
            if (!is_scalar($entry) && $entry !== null) {
                return null;
            }

            $content = str_replace("\0", '', (string) ($entry ?? ''));
        }

        if ($type === 'markdown_file') {
            $content = trim($content);
        }

        if ($type === 'image_gallery') {
            return [
                'type' => 'image_gallery',
                'content' => '',
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
        }

        if (trim($content) === '') {
            return null;
        }

        return [
            'type' => $type,
            'content' => $content,
            'css_id' => $cssId,
            'css_class' => $cssClass,
        ];
    }
}
