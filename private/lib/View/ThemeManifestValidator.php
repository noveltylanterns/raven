<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Validates and normalizes one public theme manifest payload.
 */
final class ThemeManifestValidator
{
    /**
     * @param array<string, mixed> $manifest
     * @return array{name: string, is_child_theme: bool, parent_theme: string}|null
     */
    public function normalize(string $themeSlug, array $manifest): ?array
    {
        $name = trim((string) ($manifest['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $isChildTheme = $this->toBool($manifest['is_child_theme'] ?? false);
        $parentTheme = strtolower(trim((string) ($manifest['parent_theme'] ?? '')));
        if (!$isChildTheme || !$this->isValidSlug($parentTheme) || $parentTheme === $themeSlug) {
            $parentTheme = '';
        }

        return [
            'name' => $name,
            'is_child_theme' => $isChildTheme && $parentTheme !== '',
            'parent_theme' => $parentTheme,
        ];
    }

    public function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) === 1;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
