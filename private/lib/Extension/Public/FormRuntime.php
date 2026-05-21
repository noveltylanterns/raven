<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Public/FormRuntime.php
 * Contract for extension-owned embedded form shortcode runtimes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Public;

/**
 * Defines one shortcode-renderable embedded form runtime.
 *
 * Implement this interface for shortcodes that need form submit handling
 * (contact forms, signup forms, etc.). For content-only shortcodes without
 * submit handling, implement Shortcodes instead.
 *
 * Register implementations under `extension_services.{extension}.shortcode_runtimes[]`
 * in the extension's `ext.php` boot provider.
 */
interface FormRuntime
{
    /**
     * Returns the shortcode type token, for example `contact` or `signups`.
     *
     * @return string Lowercase slug-safe type token.
     */
    public function type(): string;

    /**
     * Returns the owning extension directory key used for enabled-state checks.
     *
     * @return string Extension directory name.
     */
    public function extensionKey(): string;

    /**
     * Returns enabled form definitions available for shortcode rendering.
     *
     * @return array<int, array<string, mixed>> Form definition rows; each must have a `slug` key.
     */
    public function listEnabledForms(): array;

    /**
     * Returns one safe anchor id used after submit redirects.
     *
     * @param string $slug Form slug.
     * @return string HTML anchor id.
     */
    public function anchorId(string $slug): string;

    /**
     * Returns one submit action path for rendered form markup.
     *
     * @param string $slug Form slug.
     * @return string Absolute-path submit URL.
     */
    public function submitAction(string $slug): string;

    /**
     * Renders one embedded form block.
     *
     * @param array<string, mixed> $definition Resolved form definition from `listEnabledForms()`.
     * @param string $returnPath Sanitized return path for post-submit redirect.
     * @param string $csrfField Rendered CSRF hidden-input HTML.
     * @param string $captchaMarkup Rendered captcha widget HTML (empty when captcha is disabled).
     * @return string Rendered form HTML.
     */
    public function render(array $definition, string $returnPath, string $csrfField, string $captchaMarkup): string;

    /**
     * Handles one submit request and sends its own redirect response.
     *
     * @param string $slug Submitted form slug.
     * @param string $returnPath Sanitized return path for post-submit redirect.
     * @param callable(): (string|null) $validateCaptcha Returns a user-facing error string on failure, or null on pass.
     * @return void
     */
    public function submit(string $slug, string $returnPath, callable $validateCaptcha): void;
}
