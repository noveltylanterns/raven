<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Validates extension provider files (`lib/shortcodes.php`, `lib/fields.php`).
 */
final class ExtensionProviderValidator
{
    private ManifestContractValidator $manifestValidator;

    public function __construct(?ManifestContractValidator $manifestValidator = null)
    {
        $this->manifestValidator = $manifestValidator ?? new ManifestContractValidator();
    }

    /**
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>
     * } $context
     * @return array{
     *   valid: bool,
     *   error: string,
     *   items: array<int, array{label: string, shortcode: string}>
     * }
     */
    public function validateShortcodesProvider(string $root, string $directoryName, array $context = []): array
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'items' => [],
            ];
        }

        $providerPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/lib/shortcodes.php';
        if (!is_file($providerPath)) {
            return [
                'valid' => true,
                'error' => '',
                'items' => [],
            ];
        }

        /** @var mixed $provider */
        try {
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'lib/shortcodes.php threw an error while loading.',
                'items' => [],
            ];
        }

        if (is_callable($provider)) {
            $context['extension'] = $directoryName;
            if (!isset($context['forms']) || !is_callable($context['forms'])) {
                $context['forms'] = static fn (string $tableName): array => [];
            }

            try {
                $provider = $provider($context);
            } catch (\ArgumentCountError) {
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    return [
                        'valid' => false,
                        'error' => 'lib/shortcodes.php callable threw an error while executing.',
                        'items' => [],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'valid' => false,
                    'error' => 'lib/shortcodes.php callable threw an error while executing.',
                    'items' => [],
                ];
            }
        }

        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'lib/shortcodes.php must return an array (or callable returning an array).',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($provider as $entry) {
            if (!is_array($entry)) {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode row must be an object-like array.',
                    'items' => [],
                ];
            }

            $label = trim((string) ($entry['label'] ?? ''));
            $shortcode = trim((string) ($entry['shortcode'] ?? ''));
            $shortcode = str_replace(["\r", "\n", "\0"], '', $shortcode);
            if ($label === '' || $shortcode === '') {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode row must include non-empty "label" and "shortcode" values.',
                    'items' => [],
                ];
            }

            if (!str_starts_with($shortcode, '[') || !str_ends_with($shortcode, ']')) {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode value must use bracket shortcode syntax (for example `[example]`).',
                    'items' => [],
                ];
            }

            $items[] = [
                'label' => $label,
                'shortcode' => $shortcode,
            ];
        }

        return [
            'valid' => true,
            'error' => '',
            'items' => $items,
        ];
    }

    /**
     * @param array{extension?: string} $context
     * @return array{
     *   valid: bool,
     *   error: string,
     *   items: array<int, array{slug: string, label: string, editor: string}>
     * }
     */
    public function validateFieldsProvider(string $root, string $directoryName, array $context = []): array
    {
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'items' => [],
            ];
        }

        $providerPath = rtrim($root, '/') . '/private/ext/' . $directoryName . '/lib/fields.php';
        if (!is_file($providerPath)) {
            return [
                'valid' => true,
                'error' => '',
                'items' => [],
            ];
        }

        /** @var mixed $provider */
        try {
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'lib/fields.php threw an error while loading.',
                'items' => [],
            ];
        }

        if (is_callable($provider)) {
            $context['extension'] = $directoryName;

            try {
                $provider = $provider($context);
            } catch (\ArgumentCountError) {
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    return [
                        'valid' => false,
                        'error' => 'lib/fields.php callable threw an error while executing.',
                        'items' => [],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'valid' => false,
                    'error' => 'lib/fields.php callable threw an error while executing.',
                    'items' => [],
                ];
            }
        }

        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'lib/fields.php must return an array (or callable returning an array).',
                'items' => [],
            ];
        }

        $allowedEditors = ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file'];
        $items = [];
        $seenSlugs = [];
        foreach ($provider as $entry) {
            if (!is_array($entry)) {
                return [
                    'valid' => false,
                    'error' => 'Each fields row must be an object-like array.',
                    'items' => [],
                ];
            }

            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            $label = trim((string) ($entry['label'] ?? ''));
            $editor = strtolower(trim((string) ($entry['editor'] ?? '')));

            if ($slug === '' || $label === '' || $editor === '') {
                return [
                    'valid' => false,
                    'error' => 'Each fields row must include non-empty "slug", "label", and "editor" values.',
                    'items' => [],
                ];
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                return [
                    'valid' => false,
                    'error' => 'Field slug values must match `[a-z0-9][a-z0-9_-]*`.',
                    'items' => [],
                ];
            }
            if (!in_array($editor, $allowedEditors, true)) {
                return [
                    'valid' => false,
                    'error' => 'Field editor values must be one of: tinymce, plaintext, autobr, markdown, markdown_file.',
                    'items' => [],
                ];
            }
            if (isset($seenSlugs[$slug])) {
                return [
                    'valid' => false,
                    'error' => 'Field slugs must be unique within lib/fields.php.',
                    'items' => [],
                ];
            }
            $seenSlugs[$slug] = true;

            $items[] = [
                'slug' => $slug,
                'label' => $label,
                'editor' => $editor,
            ];
        }

        return [
            'valid' => true,
            'error' => '',
            'items' => $items,
        ];
    }
}

