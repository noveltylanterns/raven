<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorWrapper.php
 * Shared panel editor utility methods used across multiple tabbed editor controllers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Provides shared normalization helpers for panel editor fields.
 *
 * Extracted from page/channel/config editor workflows where the same pure
 * editor-field normalization logic was duplicated. No injected dependencies —
 * all methods are stateless string normalizers.
 */
final class EditorWrapper
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
     * Normalizes a channel editor-override value to a canonical editor key.
     *
     * `inherit` defers to the site-wide body-text editor setting. Accepts any case
     * and trims whitespace; falls back to `inherit` when the submitted value is not
     * a recognized option.
     *
     * @param string $value Raw editor override from channel record or form input.
     * @return string One of: inherit, tinymce, plaintext, autobr, markdown. Defaults to inherit.
     */
    public function normalizeChannelEditorOverride(string $value): string
    {
        $editor = strtolower(trim($value));

        return in_array($editor, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $editor
            : 'inherit';
    }
}
