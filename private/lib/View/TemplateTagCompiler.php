<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Compiles brace-tag template markup into executable PHP snippets.
 */
final class TemplateTagCompiler
{
    public function compileTemplate(string $source): string
    {
        $tokens = token_get_all($source);
        $compiled = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_INLINE_HTML) {
                    $compiled .= $this->compileInlineHtml($token[1]);
                    continue;
                }

                $compiled .= $token[1];
                continue;
            }

            $compiled .= $token;
        }

        return $compiled;
    }

    private function compileInlineHtml(string $html): string
    {
        if ($html === '' || !str_contains($html, '{')) {
            return $html;
        }

        $pathPattern = '([A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z0-9_]+)*)';
        $valuePathPattern = '([A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z0-9_]+)+)';
        $compiled = $html;
        $rawTagPlaceholders = [];

        $compiled = preg_replace_callback(
            '/\{if\s+not\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php if (!$__rvn_tags->truthy('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope)): ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{if\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php if ($__rvn_tags->truthy('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope)): ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace('/\{\/if\}/', '<?php endif; ?>', $compiled) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{each\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php foreach ($__rvn_tags->iter('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope) as $__rvn_item): $__rvn_scope[] = [\'item\' => $__rvn_item]; ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace('/\{\/each\}/', '<?php array_pop($__rvn_scope); endforeach; ?>', $compiled) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{raw:' . $pathPattern . '\}/',
            static function (array $matches) use (&$rawTagPlaceholders): string {
                $token = '__RVN_RAW_TAG_' . count($rawTagPlaceholders) . '__';
                $rawTagPlaceholders[$token] = '<?php echo $__rvn_tags->value('
                    . var_export((string) ($matches[1] ?? ''), true)
                    . ', $__rvn_scope, true); ?>';
                return $token;
            },
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{' . $valuePathPattern . '\}/',
            static fn (array $matches): string => '<?php echo $__rvn_tags->value('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope, false); ?>',
            $compiled
        ) ?? $compiled;

        if ($rawTagPlaceholders !== []) {
            $compiled = strtr($compiled, $rawTagPlaceholders);
        }

        return $compiled;
    }
}

