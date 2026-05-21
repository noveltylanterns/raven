<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorTabs.php
 * Shared panel editor-tab normalization and tab-preserving URL helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Lib\Security\InputSanitizer;

/**
 * Normalizes panel editor tabs and builds tab-preserving panel URLs.
 *
 * Supports three tab routing models used across core and extensions:
 * - Query-param tabs with a numeric id: `/page/edit/{id}?tab=meta`
 * - Query-param tabs without an id: `/configuration?tab=basic`
 * - Path-segment tabs: `/smallweb/{protocol}` (tab is the URL path segment)
 */
final class EditorTabs
{
    private InputSanitizer $input;

    /**
     * @param InputSanitizer $input Shared input sanitizer for raw tab values from GET/POST.
     */
    public function __construct(InputSanitizer $input)
    {
        $this->input = $input;
    }

    /**
     * Normalizes a raw tab value from GET or POST against an allowed list.
     *
     * Trims and lowercases the value, then returns the default when it is empty
     * or not in the allowed list.
     *
     * @param mixed $value Raw tab value from GET or POST (may be null or any type).
     * @param array<int, string> $allowed Allowed tab slug list.
     * @param string $default Default tab slug returned when $value is invalid or absent.
     * @return string Normalized tab slug.
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
     * Builds a tab-preserving panel URL for editors that use `?tab=` query-param navigation.
     *
     * Appends `?tab={tab}` when the requested tab differs from the default, and optionally
     * appends a hash fragment (e.g. `rvnp-editor-pane-media`) for in-page scroll targets.
     *
     * @param callable(string): string $panelUrlBuilder Panel URL builder callable.
     * @param string $basePath Base path prefix without trailing slash (e.g. '/page/edit').
     * @param int|null $id Numeric record id appended to the path, or null for query-tabbed forms.
     * @param string $tab Requested tab slug.
     * @param string $defaultTab Default tab slug; omitted from the URL when it matches $tab.
     * @param string $fragment Optional URL fragment without leading `#`; appended when non-empty.
     * @return string Panel URL for the requested tab.
     */
    public function panelEditorUrlWithTab(
        callable $panelUrlBuilder,
        string $basePath,
        ?int $id,
        string $tab,
        string $defaultTab,
        string $fragment = ''
    ): string {
        $path = $basePath . ($id !== null ? '/' . $id : '');
        $suffix = $tab === $defaultTab ? $path : $path . '?tab=' . rawurlencode($tab);
        if ($fragment !== '') {
            $suffix .= '#' . $fragment;
        }

        return $panelUrlBuilder($suffix);
    }

    /**
     * Builds a panel URL for editors where the tab is a path segment rather than a `?tab=` query param.
     *
     * Used by Smallweb and any future extension whose tabs map to URL path segments
     * (e.g. `/smallweb/{protocol}`).
     *
     * @param callable(string): string $panelUrlBuilder Panel URL builder callable.
     * @param string $basePath Base path prefix without trailing slash (e.g. '/smallweb').
     * @param string $tab Tab slug to append as the final path segment.
     * @return string Panel URL for the requested tab.
     */
    public function panelPathTabUrl(
        callable $panelUrlBuilder,
        string $basePath,
        string $tab
    ): string {
        return $panelUrlBuilder($basePath . '/' . $tab);
    }
}
