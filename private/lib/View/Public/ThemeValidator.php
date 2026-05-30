<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/ThemeValidator.php
 * Public-theme manifest validation and normalization helper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Validates and normalizes one public theme manifest payload.
 */
final class ThemeValidator
{
    /**
     * Returns whether the given value satisfies Raven's public-theme slug contract.
     *
     * @param string $slug Candidate public-theme slug.
     * @return bool True when the slug is safe for manifest/runtime use.
     */
    public function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
    }

    /**
     * Validates and normalizes one decoded `theme.json` payload.
     *
     * @param string $themeSlug Filesystem theme slug that owns the manifest.
     * @param array<string, mixed> $manifest
     * @return array{name: string, is_child_theme: bool, parent_theme: string}|null
     */
    public function normalize(string $themeSlug, array $manifest): ?array
    {
        $name = trim((string) ($manifest['name'] ?? ''));
        // Theme name is required for panel/theme selector display.
        if ($name === '') {
            return null;
        }

        $isChildTheme = $this->toBool($manifest['is_child_theme'] ?? false);
        $parentTheme = strtolower(trim((string) ($manifest['parent_theme'] ?? '')));
        // Parent is cleared when child mode is off, slug is invalid, or self-referential.
        if (!$isChildTheme || !$this->isValidSlug($parentTheme) || $parentTheme === $themeSlug) {
            $parentTheme = '';
        }

        return [
            'name' => $name,
            'is_child_theme' => $isChildTheme && $parentTheme !== '',
            'parent_theme' => $parentTheme,
        ];
    }

    /**
     * Coerces loose manifest boolean values into strict booleans.
     *
     * @param mixed $value Raw manifest field value.
     * @return bool Coerced boolean value.
     */
    private function toBool(mixed $value): bool
    {
        // Preserve strict booleans without additional coercion.
        if (is_bool($value)) {
            return $value;
        }

        // Numeric values follow 1=true, everything else=false.
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
