<?php

declare(strict_types=1);

namespace Raven\Lib\View;

use Raven\Lib\Security\InputSanitizer;

/**
 * Shared body-block normalization and definition policy helpers.
 */
final class BodyBlockPolicy
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @return array<string, array{label: string, editor: string}>
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
     * @param array<string, array{label: string, editor: string}> $definitions
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
     * @param array<string, array{label: string, editor: string}> $definitions
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
     * Normalizes one extension field list into body-block definition entries.
     *
     * @param array<int, mixed> $fields
     * @param array<string, array{label: string, editor: string}> $existing
     * @return array<string, array{label: string, editor: string}>
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
}
