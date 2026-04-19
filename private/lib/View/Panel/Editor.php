<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/Editor.php
 * Shared panel editor utility methods used across multiple tabbed editor controllers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Provides shared normalization helpers for panel editor forms.
 *
 * Extracted from ContentController, ConfigController, UserController, and
 * PreferencesController, where the same pure normalization logic was duplicated.
 * No injected dependencies — all methods are stateless string normalizers.
 */
final class Editor
{
    /**
     * Normalizes a body-text editor option value to a canonical editor key.
     *
     * Accepts any case and trims whitespace; falls back to `tinymce` when the
     * submitted value is not a recognized editor slug.
     *
     * @param string $value Raw editor option from config or form input.
     * @return string One of: tinymce, plaintext, autobr, markdown. Defaults to tinymce.
     */
    public function normalizeBodyTextEditorOption(string $value): string
    {
        $editor = strtolower(trim($value));

        return in_array($editor, ['tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'tinymce';
    }

    /**
     * Normalizes a panel-theme identifier to a valid theme slug.
     *
     * Returns `default` for empty input when `$allowDefault` is true, or `corp`
     * when `$allowDefault` is false (system-level config that must name a real theme).
     * Returns null for any unrecognized non-empty value so callers can reject it.
     *
     * @param string $theme Submitted panel-theme value.
     * @param bool $allowDefault Whether the literal `default` sentinel is permitted (user-level preference).
     * @return string|null Canonical panel-theme slug, or null when the value is unrecognized.
     */
    public function normalizePanelThemeChoice(string $theme, bool $allowDefault): ?string
    {
        $normalized = strtolower(trim($theme));

        // Empty input resolves to `default` when the user is allowed to pick it (per-user
        // preferences), or falls back to `corp` at the global config level where a real
        // theme name is required.
        if ($normalized === '') {
            return $allowDefault ? 'default' : 'corp';
        }

        if ($allowDefault && $normalized === 'default') {
            return 'default';
        }

        if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
            return $normalized;
        }

        return null;
    }
}
