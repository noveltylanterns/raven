<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

use Raven\Lib\Security\InputSanitizer;

/**
 * Normalizes editor/config tabs and builds tab-preserving panel URLs.
 */
final class PanelEditorTabService
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

    public function normalizeConfigEditorTab(mixed $value): string
    {
        $tab = strtolower($this->input->text(is_string($value) ? $value : null, 40));
        $allowed = ['basic', 'content', 'database', 'debug', 'media', 'meta', 'security', 'users'];
        if (!in_array($tab, $allowed, true)) {
            return 'basic';
        }

        return $tab;
    }

    /**
     * @param callable(string): string $panelUrlBuilder
     */
    public function configurationUrlForTab(callable $panelUrlBuilder, string $tab): string
    {
        $tab = $this->normalizeConfigEditorTab($tab);
        $query = $tab === 'basic' ? '' : ('?tab=' . rawurlencode($tab));
        return $panelUrlBuilder('/configuration' . $query);
    }
}
