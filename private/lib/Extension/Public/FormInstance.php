<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Public/FormInstance.php
 * Shortcode runtime resolver and renderer for embedded form and content runtimes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Public;

use Raven\Lib\Extension\Registry;
use Raven\Lib\Security\InputSanitizer;

/**
 * Shared shortcode runtime resolver and renderer for both content and form runtimes.
 *
 * Discovers two kinds of shortcode runtime:
 *   - Shortcodes  — general content (registered as `shortcode_runtimes`)
 *   - FormRuntime — form-capable variant (also registered in `shortcode_runtimes`)
 *
 * Both are resolved from extension_services at runtime and merged into one type-keyed map.
 * At render time, form runtimes get a full form context (definition, CSRF, captcha);
 * content runtimes receive a simpler context (slug, raw_args).
 */
final class FormInstance
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

    /**
     * @param InputSanitizer $input Shared input sanitizer for slug normalization.
     * @param string $projectRoot Absolute project root path for extension enablement checks.
     */
    public function __construct(InputSanitizer $input, string $projectRoot)
    {
        $this->input = $input;
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * Discovers all registered shortcode runtimes from the extension services container.
     *
     * Collects from the canonical `shortcode_runtimes` bucket only. Form-capable
     * runtimes use the same registry key as content-only runtimes and are
     * distinguished by interface at dispatch time. First registration wins per
     * type token so one extension cannot shadow another.
     *
     * @param array<string, mixed> $extensionServices
     * @return array<string, Shortcodes|FormRuntime>
     */
    public function discoverRuntimes(array $extensionServices): array
    {
        $runtimes = [];

        foreach ($extensionServices as $serviceBucket) {
            if (!is_array($serviceBucket)) {
                continue;
            }

            /** @var mixed $rawCandidates */
            $rawCandidates = $serviceBucket['shortcode_runtimes'] ?? [];
            if (is_object($rawCandidates)) {
                $rawCandidates = [$rawCandidates];
            }
            if (!is_array($rawCandidates)) {
                continue;
            }

            foreach ($rawCandidates as $candidate) {
                // Accept either interface; FormRuntime is not required to extend Shortcodes
                // so we check both explicitly.
                if (!$candidate instanceof Shortcodes
                    && !$candidate instanceof FormRuntime) {
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

        ksort($runtimes);
        return $runtimes;
    }

    /**
     * Returns one registered runtime by type token, or null if not found.
     *
     * @param array<string, Shortcodes|FormRuntime> $runtimes
     * @return Shortcodes|FormRuntime|null
     */
    public function runtime(string $type, array $runtimes): Shortcodes|FormRuntime|null
    {
        $normalized = strtolower(trim($type));
        return $runtimes[$normalized] ?? null;
    }

    /**
     * Returns one registered form runtime by type token, or null if not found or not a form runtime.
     *
     * @param array<string, Shortcodes|FormRuntime> $runtimes
     * @return FormRuntime|null
     */
    public function formRuntime(string $type, array $runtimes): ?FormRuntime
    {
        $runtime = $this->runtime($type, $runtimes);
        return $runtime instanceof FormRuntime ? $runtime : null;
    }

    /**
     * Returns whether the owning extension of a runtime is currently enabled.
     *
     * @param Shortcodes|FormRuntime $runtime Runtime whose extension to check.
     * @return bool True when the owning extension is enabled.
     */
    public function isRuntimeEnabled(Shortcodes|FormRuntime $runtime): bool
    {
        return $this->isExtensionEnabled($runtime->extensionKey());
    }

    /**
     * Applies shortcode substitution to an HTML string using the provided render callback.
     *
     * @param array<string, Shortcodes|FormRuntime> $runtimes
     * @param callable(string $type, string $slug, string $rawArgs): string $renderMarkup
     * @return string HTML with all recognized shortcodes replaced by their rendered output.
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
     *
     * @param string $rawPath Raw request URI string.
     * @return string Normalized absolute path, or '/' when the value is unsafe.
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
     * @param array<string, Shortcodes|FormRuntime> $runtimes
     * @param string $requestUri Raw request URI used to derive a safe return path.
     * @param string $csrfField Rendered CSRF hidden-input HTML.
     * @param callable(): string $captchaMarkup Returns rendered captcha widget HTML.
     * @return string HTML with all shortcodes resolved.
     */
    public function renderPublicShortcodes(
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

                if ($runtime instanceof FormRuntime) {
                    // Form runtime: look up the registered form definition and render with full form context.
                    $definition = $this->findFormDefinition($type, $slug, $runtimes);
                    if ($definition === null) {
                        return '';
                    }

                    return $runtime->render($definition, $returnPath, $csrfField, (string) $captchaMarkup());
                }

                if ($runtime instanceof Shortcodes) {
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
     * @param array<string, Shortcodes|FormRuntime> $runtimes
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

        $lookup = $this->formDefinitionLookup($type, $runtimes);
        $definition = $lookup[$slug] ?? null;
        return is_array($definition) ? $definition : null;
    }

    /**
     * Returns a slug-keyed map of all enabled form definitions for one form runtime type.
     *
     * @param array<string, Shortcodes|FormRuntime> $runtimes
     * @return array<string, array<string, mixed>>
     */
    private function formDefinitionLookup(string $type, array $runtimes): array
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
            $this->enabledExtensionMap = Registry::enabledMap($this->projectRoot);
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
     * @param array<string, Shortcodes|FormRuntime> $runtimes
     * @return string|null Compiled regex pattern, or null when no runtimes are registered.
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
