<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorTabs.php
 * Shared panel editor-tab normalization and tab-preserving URL helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Security\InputSanitizer;

/**
 * Normalizes panel editor tabs and builds tab-preserving panel URLs.
 */
final class EditorTabs
{
    private InputSanitizer $input;

    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * @param array<int, string> $allowed
     */
    public function normalizeEditorTab(mixed $value, array $allowed, string $default): string
    {
        $tab = strtolower($this->input->text(is_string($value) ? $value : null, 40));
        if ($tab === '' || !in_array($tab, $allowed, true)) {
            return $default;
        }

        return $tab;
    }

    /**
     * @param callable(string): string $panelUrlBuilder
     */
    public function panelEditorUrlWithTab(
        callable $panelUrlBuilder,
        string $basePath,
        ?int $id,
        string $tab,
        string $defaultTab
    ): string {
        $path = $basePath . ($id !== null ? '/' . $id : '');
        if ($tab === $defaultTab) {
            return $panelUrlBuilder($path);
        }

        return $panelUrlBuilder($path . '?tab=' . rawurlencode($tab));
    }
}
