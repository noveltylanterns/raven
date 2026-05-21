<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extension/Public/Shortcodes.php
 * Contract for extension-owned general-purpose shortcode content runtimes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Extension\Public;

/**
 * Defines one shortcode-renderable content runtime for embedding arbitrary content in page bodies.
 *
 * Use this interface for shortcodes that render content without a form submit handler.
 * For shortcodes that need submit handling (contact forms, signup forms, etc.),
 * implement FormRuntime instead — it is the form-capable variant
 * and is discovered and dispatched separately by core.
 *
 * Register implementations under `extension_services.{extension}.shortcode_runtimes[]`
 * in the extension's `ext.php` boot provider.
 *
 * Render context keys passed by core:
 *   - `slug`     (string) — slug extracted from the shortcode argument list
 *   - `raw_args` (string) — full raw argument string from inside the shortcode brackets
 */
interface Shortcodes
{
    /**
     * Returns the shortcode type token that triggers this runtime, for example `gallery` or `weather`.
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
     * Renders one embedded content block for the matched shortcode.
     *
     * @param array{slug: string, raw_args: string} $context Shortcode context provided by core.
     * @return string Rendered HTML to replace the shortcode tag.
     */
    public function render(array $context): string;
}
