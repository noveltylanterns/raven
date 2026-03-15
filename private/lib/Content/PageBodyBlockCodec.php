<?php

declare(strict_types=1);

namespace Raven\Lib\Content;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared codec + normalization helpers for page body-block payloads.
 */
final class PageBodyBlockCodec
{
    private InputSanitizer $input;
    private BodyBlockPolicy $bodyBlockPolicy;

    public function __construct(?InputSanitizer $input = null, ?BodyBlockPolicy $bodyBlockPolicy = null)
    {
        $this->input = $input ?? new InputSanitizer();
        $this->bodyBlockPolicy = $bodyBlockPolicy ?? new BodyBlockPolicy($this->input);
    }

    /**
     * @param mixed $raw
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    public function normalizeEditorSubmittedBlocks(
        mixed $raw,
        callable $typeNormalizer,
        callable $editorModeResolver,
        int $maxBlocks = 50
    ): array {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $entry) {
            if ($maxBlocks > 0 && count($blocks) >= $maxBlocks) {
                break;
            }

            $type = 'tinymce';
            $value = $entry;
            $cssId = '';
            $cssClass = '';
            if (is_array($entry)) {
                $type = (string) $typeNormalizer((string) ($entry['type'] ?? 'tinymce'));
                $value = $entry['content'] ?? '';
                $cssId = $this->normalizeCssId($entry['css_id'] ?? null);
                $cssClass = $this->normalizeCssClassList($entry['css_class'] ?? null);
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $editorMode = (string) $editorModeResolver($type);
            $normalized = $this->input->html($value !== null ? (string) $value : null, 500000);
            if ($editorMode === 'markdown_file') {
                $normalized = trim($normalized);
            }

            if ($editorMode === 'gallery') {
                $blocks[] = [
                    'type' => $type,
                    'content' => '',
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

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
     * @param mixed $raw
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    public function normalizeStoredBlocks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $entry) {
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
                    continue;
                }

                $content = str_replace("\0", '', (string) ($value ?? ''));
            } else {
                if (!is_scalar($entry) && $entry !== null) {
                    continue;
                }

                $content = str_replace("\0", '', (string) ($entry ?? ''));
            }

            if ($type === 'markdown_file') {
                $content = trim($content);
            }

            if ($type === 'image_gallery') {
                $blocks[] = [
                    'type' => 'image_gallery',
                    'content' => '',
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

            if (trim($content) === '') {
                continue;
            }

            $blocks[] = [
                'type' => $type,
                'content' => $content,
                'css_id' => $cssId,
                'css_class' => $cssClass,
            ];
        }

        return $blocks;
    }

    /**
     * @return array<int, array{type: string, content: string, css_id: string, css_class: string}>
     */
    public function decodeStoredBlocks(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return [[
                'type' => 'tinymce',
                'content' => $raw,
                'css_id' => '',
                'css_class' => '',
            ]];
        }

        $blocks = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $type = $this->normalizePersistedType((string) ($entry['type'] ?? 'tinymce'));
                $value = $entry['content'] ?? '';
                $cssId = $this->normalizeCssId($entry['css_id'] ?? null);
                $cssClass = $this->normalizeCssClassList($entry['css_class'] ?? null);
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }

                $content = str_replace("\0", '', (string) ($value ?? ''));
                if ($type === 'markdown_file') {
                    $content = trim($content);
                }

                if ($type === 'image_gallery') {
                    $blocks[] = [
                        'type' => 'image_gallery',
                        'content' => '',
                        'css_id' => $cssId,
                        'css_class' => $cssClass,
                    ];
                    continue;
                }

                if (trim($content) === '') {
                    continue;
                }

                $blocks[] = [
                    'type' => $type,
                    'content' => $content,
                    'css_id' => $cssId,
                    'css_class' => $cssClass,
                ];
                continue;
            }

            if (!is_scalar($entry) && $entry !== null) {
                continue;
            }

            $content = str_replace("\0", '', (string) ($entry ?? ''));
            if (trim($content) === '') {
                continue;
            }

            $blocks[] = [
                'type' => 'tinymce',
                'content' => $content,
                'css_id' => '',
                'css_class' => '',
            ];
        }

        return $blocks;
    }

    /**
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
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
     * @param array<int, array{type: string, content: string, css_id: string, css_class: string}> $blocks
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

    public function normalizeCssId(mixed $value): string
    {
        return $this->bodyBlockPolicy->normalizeCssId($value);
    }

    public function normalizeCssClassList(mixed $value): string
    {
        return $this->bodyBlockPolicy->normalizeCssClassList($value);
    }
}
