<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/ThemeBrace.php
 * Public-theme brace-tag compiler, cache, and runtime resolver.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

use function Raven\Lib\Security\e;

use RuntimeException;

/**
 * Compiles EE-style public-theme brace tags into PHP once, then renders from cache.
 *
 * This class is the canonical public brace-tag surface: it owns compilation,
 * cache publication, scoped path lookup, truthiness checks, comparisons, and
 * loop iteration so public rendering does not need three separate helpers.
 */
final class ThemeBrace
{
    private string $cacheDirectory;

    /**
     * @param string $cacheDirectory Absolute cache directory for compiled public templates.
     */
    public function __construct(string $cacheDirectory)
    {
        $this->cacheDirectory = rtrim($cacheDirectory, '/');
    }

    /**
     * Renders one public template through the compiled brace-tag cache.
     *
     * @param string $file Absolute source template file.
     * @param array<string, mixed> $data Template payload exposed to the compiled view scope.
     * @return string Rendered template output.
     */
    public function renderFile(string $file, array $data): string
    {
        $compiledFile = $this->compiledTemplateFile($file);

        /** @var array<int, array<string, mixed>> $__rvn_scope */
        $__rvn_scope = [$data];
        $__rvn_brace = $this;
        extract($data, EXTR_SKIP);

        if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
            define('RAVEN_VIEW_RENDER_CONTEXT', true);
        }

        ob_start();
        include $compiledFile;

        return (string) ob_get_clean();
    }

    /**
     * Resolves one brace-tag path and returns printable output.
     *
     * @param string $path Colon-delimited brace-tag path such as `page:title`.
     * @param array<int, array<string, mixed>> $scope Template scope stack, deepest scope last.
     * @param bool $raw When true, bypasses HTML escaping for trusted output.
     * @return string Safe printable output for the resolved value.
     */
    public function value(string $path, array $scope, bool $raw = false): string
    {
        $value = $this->resolve($path, $scope);
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $rendered = $value ? '1' : '';
            return $raw ? $rendered : e($rendered);
        }

        if (is_scalar($value)) {
            $rendered = (string) $value;
            return $raw ? $rendered : e($rendered);
        }

        return '';
    }

    /**
     * Resolves one truthy/falsy brace-tag condition.
     *
     * @param string $path Colon-delimited brace-tag path such as `page:published`.
     * @param array<int, array<string, mixed>> $scope Template scope stack, deepest scope last.
     * @return bool True when the resolved value should count as truthy in template conditionals.
     */
    public function truthy(string $path, array $scope): bool
    {
        $value = $this->resolve($path, $scope);
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            return $normalized !== '' && $normalized !== '0';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Evaluates one comparison expression for `{if path op value}` brace tags.
     *
     * @param string $path Colon-delimited brace-tag path to resolve.
     * @param string $operator Comparison operator: `==`, `!=`, `<`, `>`, `<=`, or `>=`.
     * @param string $rhs Right-hand-side literal from the compiled template.
     * @param array<int, array<string, mixed>> $scope Template scope stack, deepest scope last.
     * @return bool True when the comparison holds.
     */
    public function compare(string $path, string $operator, string $rhs, array $scope): bool
    {
        $value = $this->resolve($path, $scope);

        // Quoted literals are treated as explicit string comparisons so slug and title checks
        // stay exact instead of falling through to PHP's numeric coercion rules.
        $isStringRhs = $rhs !== '' && ($rhs[0] === '\'' || $rhs[0] === '"');
        if ($isStringRhs) {
            $rhsUnquoted = substr($rhs, 1, -1);
            $lhs = $value !== null ? (string) $value : '';
            return match ($operator) {
                '=='    => $lhs === $rhsUnquoted,
                '!='    => $lhs !== $rhsUnquoted,
                '<'     => $lhs < $rhsUnquoted,
                '>'     => $lhs > $rhsUnquoted,
                '<='    => $lhs <= $rhsUnquoted,
                '>='    => $lhs >= $rhsUnquoted,
                default => false,
            };
        }

        // Numeric comparisons intentionally coerce string numerals so IDs and count fields behave
        // the same whether they came from SQL, config, or template-supplied arrays.
        $rhsFloat = is_numeric($rhs) ? (float) $rhs : 0.0;
        $lhsFloat = is_int($value) || is_float($value)
            ? (float) $value
            : (is_string($value) && is_numeric($value) ? (float) $value : 0.0);

        return match ($operator) {
            '=='    => $lhsFloat == $rhsFloat,
            '!='    => $lhsFloat != $rhsFloat,
            '<'     => $lhsFloat < $rhsFloat,
            '>'     => $lhsFloat > $rhsFloat,
            '<='    => $lhsFloat <= $rhsFloat,
            '>='    => $lhsFloat >= $rhsFloat,
            default => false,
        };
    }

    /**
     * Resolves one iterable brace-tag path for `{each ...}` loops.
     *
     * @param string $path Colon-delimited brace-tag path to resolve.
     * @param array<int, array<string, mixed>> $scope Template scope stack, deepest scope last.
     * @return array<int|string, mixed> Resolved iterable data, or an empty array when not iterable.
     */
    public function iter(string $path, array $scope): array
    {
        $value = $this->resolve($path, $scope);
        return is_array($value) ? $value : [];
    }

    /**
     * Compiles one source template into a cached PHP file and returns the compiled path.
     *
     * @param string $sourceFile Absolute source template path.
     * @return string Absolute compiled template path.
     *
     * @throws RuntimeException When the source file or cache publication fails.
     */
    private function compiledTemplateFile(string $sourceFile): string
    {
        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new RuntimeException('Template source file is unreadable: ' . $sourceFile);
        }

        $this->ensureCacheDirectory();
        $mtime = @filemtime($sourceFile);
        $cacheKey = sha1($sourceFile . '|' . (string) ($mtime === false ? 0 : (int) $mtime));
        $compiledFile = $this->cacheDirectory . '/theme-brace-' . $cacheKey . '.php';

        if (is_file($compiledFile) && is_readable($compiledFile)) {
            return $compiledFile;
        }

        $source = file_get_contents($sourceFile);
        if (!is_string($source)) {
            throw new RuntimeException('Failed to read template source file: ' . $sourceFile);
        }

        $compiledSource = $this->compileTemplate($source);
        $tmpFile = $compiledFile . '.tmp.' . uniqid('', true);
        if (file_put_contents($tmpFile, $compiledSource, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write compiled template file: ' . $compiledFile);
        }

        if (!@rename($tmpFile, $compiledFile)) {
            @unlink($tmpFile);
            throw new RuntimeException('Failed to publish compiled template file: ' . $compiledFile);
        }

        @chmod($compiledFile, 0600);
        return $compiledFile;
    }

    /**
     * Ensures the compiled-template cache directory exists before publication.
     *
     * @return void
     *
     * @throws RuntimeException When the cache directory cannot be created.
     */
    private function ensureCacheDirectory(): void
    {
        if (is_dir($this->cacheDirectory)) {
            return;
        }

        if (!@mkdir($this->cacheDirectory, 0700, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Failed to create theme-brace cache directory: ' . $this->cacheDirectory);
        }
    }

    /**
     * Compiles one full PHP/HTML template source string.
     *
     * @param string $source Raw template source with brace-tag markup.
     * @return string PHP source with all supported brace tags compiled inline.
     */
    private function compileTemplate(string $source): string
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

    /**
     * Compiles brace-tag markup that appears inside one inline-HTML token.
     *
     * @param string $html Raw inline HTML chunk from `token_get_all()`.
     * @return string Inline HTML with supported brace tags replaced by PHP snippets.
     */
    private function compileInlineHtml(string $html): string
    {
        if ($html === '' || !str_contains($html, '{')) {
            return $html;
        }

        $pathPattern = '([A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z0-9_]+)*)';
        $valuePathPattern = '([A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z0-9_]+)+)';
        // Optional comparison suffix: operator followed by a quoted string or bare number.
        // {if not} and {ifelse not} remain pure truthy-negation helpers to keep the template
        // grammar simple and predictable for authors.
        $cmpPattern = '(?:\s*(==|!=|<=|>=|<|>)\s*(\'[^\']*\'|"[^"]*"|-?\d+(?:\.\d+)?))?';
        $compiled = $html;
        $rawTagPlaceholders = [];

        // Process `ifelse not` before plain `ifelse` so the literal word "not" never gets
        // consumed as though it were the first segment of a template path.
        $compiled = preg_replace_callback(
            '/\{ifelse\s+not\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php elseif (!$__rvn_brace->truthy('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope)): ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{ifelse\s+' . $pathPattern . $cmpPattern . '\}/',
            static function (array $matches): string {
                $path = (string) ($matches[1] ?? '');
                $op = (string) ($matches[2] ?? '');
                $rhs = (string) ($matches[3] ?? '');
                if ($op !== '') {
                    return '<?php elseif ($__rvn_brace->compare('
                        . var_export($path, true) . ', '
                        . var_export($op, true) . ', '
                        . var_export($rhs, true)
                        . ', $__rvn_scope)): ?>';
                }

                return '<?php elseif ($__rvn_brace->truthy('
                    . var_export($path, true)
                    . ', $__rvn_scope)): ?>';
            },
            $compiled
        ) ?? $compiled;

        // Process `if not` before plain `if` for the same reason: `not` is grammar, not data.
        $compiled = preg_replace_callback(
            '/\{if\s+not\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php if (!$__rvn_brace->truthy('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope)): ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{if\s+' . $pathPattern . $cmpPattern . '\}/',
            static function (array $matches): string {
                $path = (string) ($matches[1] ?? '');
                $op = (string) ($matches[2] ?? '');
                $rhs = (string) ($matches[3] ?? '');
                if ($op !== '') {
                    return '<?php if ($__rvn_brace->compare('
                        . var_export($path, true) . ', '
                        . var_export($op, true) . ', '
                        . var_export($rhs, true)
                        . ', $__rvn_scope)): ?>';
                }

                return '<?php if ($__rvn_brace->truthy('
                    . var_export($path, true)
                    . ', $__rvn_scope)): ?>';
            },
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace('/\{\/if\}/', '<?php endif; ?>', $compiled) ?? $compiled;
        $compiled = preg_replace('/\{else\}/', '<?php else: ?>', $compiled) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{each\s+' . $pathPattern . '\}/',
            static fn (array $matches): string => '<?php foreach ($__rvn_brace->iter('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope) as $__rvn_item): $__rvn_scope[] = [\'item\' => $__rvn_item]; ?>',
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace('/\{\/each\}/', '<?php array_pop($__rvn_scope); endforeach; ?>', $compiled) ?? $compiled;

        // Raw tags are deferred until after escaped value replacement so `{raw:...}` does not get
        // accidentally matched by the broader `{foo:bar}` value-tag pattern.
        $compiled = preg_replace_callback(
            '/\{raw:' . $pathPattern . '\}/',
            static function (array $matches) use (&$rawTagPlaceholders): string {
                $token = '__RVN_RAW_TAG_' . count($rawTagPlaceholders) . '__';
                $rawTagPlaceholders[$token] = '<?php echo $__rvn_brace->value('
                    . var_export((string) ($matches[1] ?? ''), true)
                    . ', $__rvn_scope, true); ?>';
                return $token;
            },
            $compiled
        ) ?? $compiled;

        $compiled = preg_replace_callback(
            '/\{' . $valuePathPattern . '\}/',
            static fn (array $matches): string => '<?php echo $__rvn_brace->value('
                . var_export((string) ($matches[1] ?? ''), true)
                . ', $__rvn_scope, false); ?>',
            $compiled
        ) ?? $compiled;

        if ($rawTagPlaceholders !== []) {
            $compiled = strtr($compiled, $rawTagPlaceholders);
        }

        return $compiled;
    }

    /**
     * Resolves one scoped brace-tag path (`foo:bar:baz`) to its runtime value.
     *
     * @param string $path Colon-delimited brace-tag path.
     * @param array<int, array<string, mixed>> $scope Template scope stack, deepest scope last.
     * @return mixed Resolved runtime value, or null when the path is unknown.
     */
    private function resolve(string $path, array $scope): mixed
    {
        $segments = array_values(array_filter(explode(':', trim($path)), static fn (string $value): bool => $value !== ''));
        if ($segments === []) {
            return null;
        }

        $first = array_shift($segments);
        if (!is_string($first) || $first === '') {
            return null;
        }

        $found = false;
        $value = $this->lookupFirstSegment($first, $scope, $found);
        if (!$found) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                return null;
            }

            $value = $this->lookupNestedSegment($value, $segment, $found);
            if (!$found) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Resolves the first path segment by walking scope layers from inner to outer.
     *
     * @param string $segment First brace-tag path segment to resolve.
     * @param array<int, array<string, mixed>> $scope Template scope stack, deepest scope last.
     * @param bool $found Output flag set true when the segment is resolved.
     * @return mixed Resolved value, or null when not found.
     */
    private function lookupFirstSegment(string $segment, array $scope, bool &$found): mixed
    {
        for ($index = count($scope) - 1; $index >= 0; $index--) {
            $layer = $scope[$index] ?? null;
            if (!is_array($layer)) {
                continue;
            }

            if (array_key_exists($segment, $layer)) {
                $found = true;
                return $layer[$segment];
            }

            if (ctype_digit($segment)) {
                $intKey = (int) $segment;
                if (array_key_exists($intKey, $layer)) {
                    $found = true;
                    return $layer[$intKey];
                }
            }
        }

        $found = false;
        return null;
    }

    /**
     * Resolves one nested path segment against an already-resolved value.
     *
     * @param mixed $value Current resolved parent value.
     * @param string $segment Next brace-tag path segment.
     * @param bool $found Output flag set true when the nested segment is resolved.
     * @return mixed Resolved nested value, or null when not found.
     */
    private function lookupNestedSegment(mixed $value, string $segment, bool &$found): mixed
    {
        if (is_array($value)) {
            if (array_key_exists($segment, $value)) {
                $found = true;
                return $value[$segment];
            }

            if (ctype_digit($segment)) {
                $intKey = (int) $segment;
                if (array_key_exists($intKey, $value)) {
                    $found = true;
                    return $value[$intKey];
                }
            }

            $found = false;
            return null;
        }

        if (is_object($value)) {
            $properties = get_object_vars($value);
            if (array_key_exists($segment, $properties)) {
                $found = true;
                return $properties[$segment];
            }
        }

        $found = false;
        return null;
    }
}
