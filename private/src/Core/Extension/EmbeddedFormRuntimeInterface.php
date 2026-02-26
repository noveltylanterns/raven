<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Extension/EmbeddedFormRuntimeInterface.php
 * Contract for extension-owned embedded form runtime handlers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Extension;

/**
 * Defines one shortcode-renderable embedded form runtime.
 */
interface EmbeddedFormRuntimeInterface
{
    /**
     * Returns shortcode type token, for example `contact` or `signups`.
     */
    public function type(): string;

    /**
     * Returns owning extension directory key used for enabled-state checks.
     */
    public function extensionKey(): string;

    /**
     * Returns enabled form definitions available for shortcode rendering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEnabledForms(): array;

    /**
     * Returns one safe anchor id used after submit redirects.
     */
    public function anchorId(string $slug): string;

    /**
     * Returns one submit action path for rendered form markup.
     */
    public function submitAction(string $slug): string;

    /**
     * Renders one embedded form block.
     *
     * @param array<string, mixed> $definition
     */
    public function render(array $definition, string $returnPath, string $csrfField, string $captchaMarkup): string;

    /**
     * Handles one submit request and sends its own redirect response.
     *
     * @param callable(): (string|null) $validateCaptcha
     */
    public function submit(string $slug, string $returnPath, callable $validateCaptcha): void;
}

