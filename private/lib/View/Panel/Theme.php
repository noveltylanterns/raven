<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/Theme.php
 * Panel-theme normalization and effective-theme resolution primitives.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

use Raven\Core\Config;

/**
 * Resolves canonical panel-theme slugs from config and user preference values.
 */
final class Theme
{
    /**
     * Normalizes one panel-theme identifier to a canonical Raven slug.
     *
     * Accepts Raven slugs (`corp`, `ice`, `midnight`) and legacy Bootstrap
     * aliases (`default`/`light`/`dark`) for compatibility with older stored
     * preferences. Returns null when a non-empty value is unrecognized.
     *
     * @param string $theme Raw theme value from config, form input, or preferences.
     * @param bool $allowDefault Whether the literal `default` sentinel is allowed.
     * @return string|null Canonical theme slug, `default` sentinel, or null when invalid.
     */
    public function normalizeChoice(string $theme, bool $allowDefault): ?string
    {
        $normalized = strtolower(trim($theme));

        if ($normalized === '') {
            return $allowDefault ? 'default' : 'corp';
        }

        if ($allowDefault && $normalized === 'default') {
            return 'default';
        }

        if ($normalized === 'light') {
            return 'ice';
        }

        if ($normalized === 'dark') {
            return 'midnight';
        }

        if (in_array($normalized, ['corp', 'ice', 'midnight'], true)) {
            return $normalized;
        }

        return null;
    }

    /**
     * Resolves the site-default panel theme from runtime config.
     *
     * @param Config $config Runtime configuration reader.
     * @return string Canonical default panel theme slug.
     */
    public function defaultFromConfig(Config $config): string
    {
        $theme = (string) $config->get('panel.theme', 'corp');
        return $this->normalizeChoice($theme, false) ?? 'corp';
    }

    /**
     * Resolves one user's effective panel theme from stored preferences.
     *
     * @param array<string, mixed>|null $preferences User preference row, or null.
     * @param string $defaultTheme Canonical default panel theme slug.
     * @return string Effective panel theme slug for template rendering.
     */
    public function effectiveFromPreferences(?array $preferences, string $defaultTheme): string
    {
        if (!is_array($preferences)) {
            return $defaultTheme;
        }

        $candidate = $this->normalizeChoice((string) ($preferences['theme'] ?? 'default'), true);
        if (!is_string($candidate)) {
            return $defaultTheme;
        }

        if ($candidate === 'default') {
            return $defaultTheme;
        }

        return $candidate;
    }
}
