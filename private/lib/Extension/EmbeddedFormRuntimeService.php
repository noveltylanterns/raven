<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

use Raven\Core\Extension\EmbeddedFormRuntimeInterface;
use Raven\Core\Extension\EmbeddedShortcodeRuntimeInterface;
use Raven\Lib\Extension\ExtensionRegistry;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared shortcode runtime resolver and renderer for both content and form runtimes.
 *
 * Discovers two kinds of shortcode runtime:
 *   - EmbeddedShortcodeRuntimeInterface — general content (registered as `shortcode_runtimes`)
 *   - EmbeddedFormRuntimeInterface      — form-capable variant (registered as `embedded_form_runtimes`
 *     for backwards compatibility; `shortcode_runtimes` is now the canonical key for both)
 *
 * Both are resolved from extension_services at runtime and merged into one type-keyed map.
 * At render time, form runtimes get a full form context (definition, CSRF, captcha);
 * content runtimes receive a simpler context (slug, raw_args).
 */
final class EmbeddedFormRuntimeService
{
    private InputSanitizer $input;
    private string $projectRoot;
    /** @var array<string, bool>|null */
    private ?array $enabledExtensionMap = null;
    /**
     * Form-definition lookup cache keyed by type then slug.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $embeddedFormLookupCache = [];

    public function __construct(InputSanitizer $input, string $projectRoot)
    {
        $this->input = $input;
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * Discovers all registered shortcode runtimes from the extension services container.
     *
     * Collects from both `shortcode_runtimes` (canonical, all types) and
     * `embedded_form_runtimes` (legacy key kept for backwards compatibility).
     * First registration wins per type token so one extension cannot shadow another.
     *
     * @param array<string, mixed> $extensionServices
     * @return array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface>
     */
    public function discoverRuntimes(array $extensionServices): array
    {
        $runtimes = [];

        foreach ($extensionServices as $serviceBucket) {
            if (!is_array($serviceBucket)) {
                continue;
            }

            // Accept both the canonical new key and the legacy form-only key.
            foreach (['shortcode_runtimes', 'embedded_form_runtimes'] as $bucketKey) {
                /** @var mixed $rawCandidates */
                $rawCandidates = $serviceBucket[$bucketKey] ?? [];
                if (is_object($rawCandidates)) {
                    $rawCandidates = [$rawCandidates];
                }
                if (!is_array($rawCandidates)) {
                    continue;
                }

                foreach ($rawCandidates as $candidate) {
                    // Accept either interface; EmbeddedFormRuntimeInterface is not required to
                    // extend EmbeddedShortcodeRuntimeInterface so we check both explicitly.
                    if (!$candidate instanceof EmbeddedShortcodeRuntimeInterface
                        && !$candidate instanceof EmbeddedFormRuntimeInterface) {
                        continue;
                    }

                    $type = strtolower(trim($candidate->type()));
                    if ($type === '' || $this->input->slug($type) === null) {
                        continue;
                    }

                    if (!isset($runtimes[$type])) {
                        $runtimes[$type] = $candidate;
                    }
                }
            }
        }

        ksort($runtimes);
        return $runtimes;
    }

    /**
     * Returns one registered runtime by type token, or null if not found.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
     * @return EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface|null
     */
    public function runtime(string $type, array $runtimes): EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface|null
    {
        $normalized = strtolower(trim($type));
        return $runtimes[$normalized] ?? null;
    }

    /**
     * Returns one registered form runtime by type token, or null if not found or not a form runtime.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
     */
    public function formRuntime(string $type, array $runtimes): ?EmbeddedFormRuntimeInterface
    {
        $runtime = $this->runtime($type, $runtimes);
        return $runtime instanceof EmbeddedFormRuntimeInterface ? $runtime : null;
    }

    /**
     * Returns whether the owning extension of a runtime is currently enabled.
     *
     * @param EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface $runtime
     */
    public function isRuntimeEnabled(EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface $runtime): bool
    {
        return $this->isExtensionEnabled($runtime->extensionKey());
    }

    /**
     * Applies shortcode substitution to an HTML string using the provided render callback.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
     * @param callable(string $type, string $slug, string $rawArgs): string $renderMarkup
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

                $runtime = $this->runtime($type, $runtimes);
                if ($runtime === null || !$this->isRuntimeEnabled($runtime)) {
                    return '';
                }

                return $renderMarkup($type, $slug, $rawArgumentChunk);
            },
            $html
        );
    }

    /**
     * Sanitizes a raw request URI into a safe return path for post-submit redirects.
     */
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
     * Resolves and renders all shortcodes in public-route HTML.
     *
     * Form runtimes receive a full form context (definition, return path, CSRF, captcha).
     * Content runtimes receive a simple context (slug, raw_args) and are rendered directly.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
     * @param callable(): string $captchaMarkup
     */
    public function renderShortcodesForPublicRoute(
        string $html,
        array $runtimes,
        string $requestUri,
        string $csrfField,
        callable $captchaMarkup
    ): string {
        $returnPath = $this->sanitizeReturnPath($requestUri);

        return $this->renderShortcodes(
            $html,
            $runtimes,
            function (string $type, string $slug, string $rawArgs) use ($runtimes, $returnPath, $csrfField, $captchaMarkup): string {
                $runtime = $this->runtime($type, $runtimes);

                if ($runtime instanceof EmbeddedFormRuntimeInterface) {
                    // Form runtime: look up the registered form definition and render with full form context.
                    $definition = $this->findFormDefinition($type, $slug, $runtimes);
                    if ($definition === null) {
                        return '';
                    }

                    return $runtime->render($definition, $returnPath, $csrfField, (string) $captchaMarkup());
                }

                if ($runtime instanceof EmbeddedShortcodeRuntimeInterface) {
                    // Content runtime: render with lightweight slug/args context; no form wiring needed.
                    return $runtime->render(['slug' => $slug, 'raw_args' => $rawArgs]);
                }

                return '';
            }
        );
    }

    /**
     * Looks up a form definition for a form runtime by type and slug.
     *
     * Returns null when the runtime is not found, not enabled, or has no matching definition.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
     * @return array<string, mixed>|null
     */
    public function findFormDefinition(string $type, string $slug, array $runtimes): ?array
    {
        $type = strtolower(trim($type));
        $slug = strtolower(trim($slug));
        if ($type === '' || $slug === '') {
            return null;
        }

        $runtime = $this->formRuntime($type, $runtimes);
        if ($runtime === null || !$this->isRuntimeEnabled($runtime)) {
            return null;
        }

        $lookup = $this->formDefinitionLookupByType($type, $runtimes);
        $definition = $lookup[$slug] ?? null;
        return is_array($definition) ? $definition : null;
    }

    /**
     * Returns a slug-keyed map of all enabled form definitions for one form runtime type.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
     * @return array<string, array<string, mixed>>
     */
    private function formDefinitionLookupByType(string $type, array $runtimes): array
    {
        if (isset($this->embeddedFormLookupCache[$type])) {
            return $this->embeddedFormLookupCache[$type];
        }

        $runtime = $this->formRuntime($type, $runtimes);
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
     * Extracts the shortcode slug from the raw argument string.
     *
     * Supported formats:
     * - `slug="my-form"`
     * - `slug='my-form'`
     * - `slug=my-form`
     * - `my-form` (compact positional shorthand)
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
     * Builds a regex pattern that matches any registered shortcode type token.
     *
     * @param array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> $runtimes
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
