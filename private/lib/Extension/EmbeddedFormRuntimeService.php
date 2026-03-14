<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

use Raven\Core\Extension\EmbeddedFormRuntimeInterface;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared embedded-form runtime resolver and shortcode renderer.
 */
final class EmbeddedFormRuntimeService
{
    private InputSanitizer $input;
    private string $projectRoot;
    /** @var array<string, bool>|null */
    private ?array $enabledExtensionMap = null;
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $embeddedFormLookupCache = [];

    public function __construct(InputSanitizer $input, string $projectRoot)
    {
        $this->input = $input;
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * @param array<string, EmbeddedFormRuntimeInterface> $runtimes
     */
    public function runtime(string $type, array $runtimes): ?EmbeddedFormRuntimeInterface
    {
        $normalized = strtolower(trim($type));
        return $runtimes[$normalized] ?? null;
    }

    public function isRuntimeEnabled(EmbeddedFormRuntimeInterface $runtime): bool
    {
        return $this->isExtensionEnabled($runtime->extensionKey());
    }

    /**
     * @param array<string, EmbeddedFormRuntimeInterface> $runtimes
     * @param callable(string, array<string, mixed>): string $renderMarkup
     */
    public function renderShortcodes(string $html, array $runtimes, callable $renderMarkup): string
    {
        if ($html === '') {
            return '';
        }

        $shortcodePattern = $this->shortcodePattern($runtimes);
        if ($shortcodePattern === null) {
            return $html;
        }

        return (string) preg_replace_callback(
            $shortcodePattern,
            function (array $matches) use ($runtimes, $renderMarkup): string {
                $type = strtolower((string) ($matches[1] ?? ''));
                $rawArgumentChunk = (string) ($matches[2] ?? '');
                $slug = $this->extractSlug($rawArgumentChunk);
                if ($type === '' || $slug === '') {
                    return '';
                }

                $definition = $this->findDefinition($type, $slug, $runtimes);
                if ($definition === null) {
                    return '';
                }

                return $renderMarkup($type, $definition);
            },
            $html
        );
    }

    public function sanitizeReturnPath(string $rawPath): string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '' || str_contains($rawPath, "\0") || str_contains($rawPath, '\\')) {
            return '/';
        }

        $path = (string) parse_url($rawPath, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path;
    }

    /**
     * @param array<string, EmbeddedFormRuntimeInterface> $runtimes
     * @return array<string, mixed>|null
     */
    private function findDefinition(string $type, string $slug, array $runtimes): ?array
    {
        $type = strtolower(trim($type));
        $slug = strtolower(trim($slug));
        if ($type === '' || $slug === '') {
            return null;
        }

        $runtime = $this->runtime($type, $runtimes);
        if ($runtime === null || !$this->isRuntimeEnabled($runtime)) {
            return null;
        }

        $lookup = $this->extensionFormLookupByType($type, $runtimes);
        $definition = $lookup[$slug] ?? null;
        return is_array($definition) ? $definition : null;
    }

    /**
     * @param array<string, EmbeddedFormRuntimeInterface> $runtimes
     * @return array<string, array<string, mixed>>
     */
    private function extensionFormLookupByType(string $type, array $runtimes): array
    {
        if (isset($this->embeddedFormLookupCache[$type])) {
            return $this->embeddedFormLookupCache[$type];
        }

        $runtime = $this->runtime($type, $runtimes);
        if ($runtime === null) {
            $this->embeddedFormLookupCache[$type] = [];
            return [];
        }

        $forms = $runtime->listEnabledForms();
        $lookup = [];
        foreach ($forms as $form) {
            if (!is_array($form)) {
                continue;
            }

            $slug = $this->input->slug((string) ($form['slug'] ?? ''));
            if ($slug === null || $slug === '') {
                continue;
            }

            $lookup[strtolower($slug)] = $form;
        }

        $this->embeddedFormLookupCache[$type] = $lookup;
        return $lookup;
    }

    private function isExtensionEnabled(string $extensionName): bool
    {
        if ($this->enabledExtensionMap === null) {
            $this->enabledExtensionMap = ExtensionRegistry::enabledMap($this->projectRoot);
        }

        return !empty($this->enabledExtensionMap[$extensionName]);
    }

    /**
     * Supported formats:
     * - `slug="my-form"`
     * - `slug='my-form'`
     * - `slug=my-form`
     * - `my-form`
     */
    private function extractSlug(string $rawArgs): string
    {
        $args = html_entity_decode($rawArgs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $args = trim($args);
        if ($args === '') {
            return '';
        }

        // Handle explicit `slug=...` first (quoted or unquoted).
        if (preg_match('/(?:^|\s)slug\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([a-z0-9_-]+))/i', $args, $matches) === 1) {
            $candidate = '';
            for ($index = 1; $index <= 3; $index++) {
                $value = trim((string) ($matches[$index] ?? ''));
                if ($value !== '') {
                    $candidate = $value;
                    break;
                }
            }

            $slug = $this->input->slug($candidate);
            return $slug ?? '';
        }

        // Also allow compact shorthand: `[type my-form]`.
        if (preg_match('/^([a-z0-9_-]+)\s*$/i', $args, $matches) === 1) {
            $slug = $this->input->slug((string) ($matches[1] ?? ''));
            return $slug ?? '';
        }

        return '';
    }

    /**
     * @param array<string, EmbeddedFormRuntimeInterface> $runtimes
     */
    private function shortcodePattern(array $runtimes): ?string
    {
        $types = array_keys($runtimes);
        if ($types === []) {
            return null;
        }

        $escapedTypes = array_map(
            static fn (string $token): string => preg_quote($token, '/'),
            $types
        );

        return '/\[(' . implode('|', $escapedTypes) . ')\b([^\]]*)\]/i';
    }
}
