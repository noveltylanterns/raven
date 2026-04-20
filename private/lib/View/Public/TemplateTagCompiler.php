<?php

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Compiles brace-tag template markup into executable PHP snippets.
 */
final class TemplateTagCompiler
{
    /**
     * Compiles a full template source file, replacing brace tags with PHP code.
     *
     * @param string $source Raw PHP/HTML template source with brace-tag markup.
     * @return string PHP source with all recognised brace tags replaced by inline PHP.
     */
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
        // Optional comparison suffix: operator followed by a quoted string or bare number.
        // Captured as two extra groups: (2) operator, (3) right-hand side literal.
        // {if not} and {ifelse not} are pure truthy-negation and do not support comparisons;
        // use != for negated equality checks instead.
        $cmpPattern = '(?:\s*(==|!=|<=|>=|<|>)\s*(\'[^\']*\'|"[^"]*"|-?\d+(?:\.\d+)?))?';
        $compiled = $html;
        $rawTagPlaceholders = [];

        // Process {ifelse not} before {ifelse} so "not" is not mistaken for a path segment.
        $compiled = preg_replace_callback(
            '/\{ifelse\s+not\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php elseif (!$__rvn_tags->truthy('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope)): ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{ifelse\s+' . $pathPattern . $cmpPattern . '\}/',
            static function (array $matches): string {
                $path = (string) ($matches[1] ?? '');
                $op   = (string) ($matches[2] ?? '');
                $rhs  = (string) ($matches[3] ?? '');
                if ($op !== '') {
                    // Emit a compare() call when an explicit operator is present.
                    return '<?php elseif ($__rvn_tags->compare('
                        . var_export($path, true) . ', '
                        . var_export($op, true) . ', '
                        . var_export($rhs, true)
                        . ', $__rvn_scope)): ?>';
                }
                return '<?php elseif ($__rvn_tags->truthy('
                    . var_export($path, true)
                    . ', $__rvn_scope)): ?>';
            },
            $compiled
        ) ?? $compiled;

        // Process {if not} before {if} so "not" is not mistaken for a path segment.
        $compiled = preg_replace_callback(
            '/\{if\s+not\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php if (!$__rvn_tags->truthy('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope)): ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{if\s+' . $pathPattern . $cmpPattern . '\}/',
            static function (array $matches): string {
                $path = (string) ($matches[1] ?? '');
                $op   = (string) ($matches[2] ?? '');
                $rhs  = (string) ($matches[3] ?? '');
                if ($op !== '') {
                    // Emit a compare() call when an explicit operator is present.
                    return '<?php if ($__rvn_tags->compare('
                        . var_export($path, true) . ', '
                        . var_export($op, true) . ', '
                        . var_export($rhs, true)
                        . ', $__rvn_scope)): ?>';
                }
                return '<?php if ($__rvn_tags->truthy('
                    . var_export($path, true)
                    . ', $__rvn_scope)): ?>';
            },
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace('/\{\/if\}/', '<?php endif; ?>', $compiled) ?? $compiled;
        $compiled = preg_replace('/\{else\}/', '<?php else: ?>', $compiled) ?? $compiled;

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
