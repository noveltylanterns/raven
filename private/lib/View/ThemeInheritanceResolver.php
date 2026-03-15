<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Resolves child-to-parent public theme inheritance chains.
 */
final class ThemeInheritanceResolver
{
    /**
     * @param array<string, array{name: string, is_child_theme: bool, parent_theme: string}> $manifests
     * @return array<int, string>
     */
    public function resolve(array $manifests, string $themeSlug): array
    {
        $themeSlug = strtolower(trim($themeSlug));
        if ($themeSlug === '' || !isset($manifests[$themeSlug])) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $themeSlug;
        $maxDepth = 12;

        for ($index = 0; $index < $maxDepth; $index++) {
            if (isset($visited[$current]) || !isset($manifests[$current])) {
                break;
            }

            $visited[$current] = true;
            $chain[] = $current;

            $manifest = $manifests[$current];
            $isChildTheme = (bool) ($manifest['is_child_theme'] ?? false);
            $parentTheme = (string) ($manifest['parent_theme'] ?? '');
            if (!$isChildTheme || $parentTheme === '' || !isset($manifests[$parentTheme])) {
                break;
            }

            $current = $parentTheme;
        }

        return $chain;
    }
}

