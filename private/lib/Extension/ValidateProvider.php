<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/ValidateProvider.php
 * Validates extension provider files (shortcodes.php, fields.php).
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Validates extension provider files (`shortcodes.php`, `fields.php`).
 */
final class ValidateProvider
{
    private ValidateManifest $manifestValidator;

    /**
     * @param ValidateManifest|null $manifestValidator Optional manifest validator; defaults to a fresh instance.
     */
    public function __construct(?ValidateManifest $manifestValidator = null)
    {
        $this->manifestValidator = $manifestValidator ?? new ValidateManifest();
    }

    /**
     * Loads and validates one extension shortcode provider file.
     *
     * Returns a valid result with an empty item list when the provider file is absent.
     *
     * @param string $root Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{
     *   extension?: string,
     *   forms?: callable(string): array<int, array{name: string, slug: string}>,
     *   config?: \Raven\Core\Config
     * } $context Optional context passed to the shortcode provider callable.
     * @return array{valid: bool, error: string, items: array<int, array{label: string, shortcode: string}>}
     *         Validation result with normalized shortcode item list on success.
     */
    public function validateShortcodesProvider(string $root, string $directoryName, array $context = []): array
    {
        // Reject unsafe extension directory names before provider loading.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'items' => [],
            ];
        }

        $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directoryName;
        $providerPath = Resolver::providerPath($extensionRoot, 'shortcodes.php');
        // Missing provider is valid and yields an empty shortcode list.
        if ($providerPath === null) {
            return [
                'valid' => true,
                'error' => '',
                'items' => [],
            ];
        }

        /** @var mixed $provider */
        // Isolate provider include errors into validation failures.
        try {
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'shortcodes.php threw an error while loading.',
                'items' => [],
            ];
        }

        // Callable providers are executed to obtain their runtime shortcode rows.
        if (is_callable($provider)) {
            $context['extension'] = $directoryName;
            // Provide default forms callback when caller did not supply one.
            if (!isset($context['forms']) || !is_callable($context['forms'])) {
                $context['forms'] = static fn (string $tableName): array => [];
            }

            // Prefer context-aware callable signature, then fall back to zero-arg form.
            try {
                $provider = $provider($context);
            } catch (\ArgumentCountError) {
                // Back-compat fallback for legacy zero-argument provider callables.
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    return [
                        'valid' => false,
                        'error' => 'shortcodes.php callable threw an error while executing.',
                        'items' => [],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'valid' => false,
                    'error' => 'shortcodes.php callable threw an error while executing.',
                    'items' => [],
                ];
            }
        }

        // Provider output must be an array of shortcode rows.
        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'shortcodes.php must return an array (or callable returning an array).',
                'items' => [],
            ];
        }

        $items = [];
        // Validate and normalize each shortcode definition row.
        foreach ($provider as $entry) {
            // Each row must be object-like associative data.
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
            // Label and shortcode token are both required.
            if ($label === '' || $shortcode === '') {
                return [
                    'valid' => false,
                    'error' => 'Each shortcode row must include non-empty "label" and "shortcode" values.',
                    'items' => [],
                ];
            }

            // Shortcode token must use bracketed shortcode syntax.
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
     * Loads and validates one extension field provider file.
     *
     * Returns a valid result with an empty item list when the provider file is absent.
     *
     * @param string $root Project root path.
     * @param string $directoryName Extension directory name.
     * @param array{extension?: string} $context Optional context passed to the fields provider callable.
     * @return array{valid: bool, error: string, items: array<int, array{slug: string, label: string, editor: string}>}
     *         Validation result with normalized field item list on success.
     */
    public function validateFieldsProvider(string $root, string $directoryName, array $context = []): array
    {
        // Reject unsafe extension directory names before provider loading.
        if (!$this->manifestValidator->isSafeDirectoryName($directoryName)) {
            return [
                'valid' => false,
                'error' => 'Invalid extension directory name.',
                'items' => [],
            ];
        }

        $extensionRoot = rtrim($root, '/') . '/private/ext/' . $directoryName;
        $providerPath = Resolver::providerPath($extensionRoot, 'fields.php');
        // Missing provider is valid and yields an empty fields list.
        if ($providerPath === null) {
            return [
                'valid' => true,
                'error' => '',
                'items' => [],
            ];
        }

        /** @var mixed $provider */
        // Isolate provider include errors into validation failures.
        try {
            $provider = require $providerPath;
        } catch (\Throwable) {
            return [
                'valid' => false,
                'error' => 'fields.php threw an error while loading.',
                'items' => [],
            ];
        }

        // Callable providers are executed to obtain their runtime fields rows.
        if (is_callable($provider)) {
            $context['extension'] = $directoryName;

            // Prefer context-aware callable signature, then fall back to zero-arg form.
            try {
                $provider = $provider($context);
            } catch (\ArgumentCountError) {
                // Back-compat fallback for legacy zero-argument provider callables.
                try {
                    $provider = $provider();
                } catch (\Throwable) {
                    return [
                        'valid' => false,
                        'error' => 'fields.php callable threw an error while executing.',
                        'items' => [],
                    ];
                }
            } catch (\Throwable) {
                return [
                    'valid' => false,
                    'error' => 'fields.php callable threw an error while executing.',
                    'items' => [],
                ];
            }
        }

        // Provider output must be an array of field rows.
        if (!is_array($provider)) {
            return [
                'valid' => false,
                'error' => 'fields.php must return an array (or callable returning an array).',
                'items' => [],
            ];
        }

        $allowedEditors = ['tinymce', 'plaintext', 'autobr', 'markdown', 'markdown_file'];
        $items = [];
        $seenSlugs = [];
        // Validate and normalize each field definition row.
        foreach ($provider as $entry) {
            // Each row must be object-like associative data.
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

            // Slug/label/editor are required for each field row.
            if ($slug === '' || $label === '' || $editor === '') {
                return [
                    'valid' => false,
                    'error' => 'Each fields row must include non-empty "slug", "label", and "editor" values.',
                    'items' => [],
                ];
            }
            // Slug must follow extension field slug constraints.
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slug) !== 1) {
                return [
                    'valid' => false,
                    'error' => 'Field slug values must match `[a-z0-9][a-z0-9_-]*`.',
                    'items' => [],
                ];
            }
            // Editor token must be one of the supported editor identifiers.
            if (!in_array($editor, $allowedEditors, true)) {
                return [
                    'valid' => false,
                    'error' => 'Field editor values must be one of: tinymce, plaintext, autobr, markdown, markdown_file.',
                    'items' => [],
                ];
            }
            // Slugs must be unique within one provider payload.
            if (isset($seenSlugs[$slug])) {
                return [
                    'valid' => false,
                    'error' => 'Field slugs must be unique within fields.php.',
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
