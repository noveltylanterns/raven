<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/View/TemplateTagEngine.php
 * Public-template brace-tag compiler and renderer.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\View;

use RuntimeException;

use function Raven\Core\Support\e;

/**
 * Compiles EE-style brace tags into PHP once, then renders from cache.
 */
final class TemplateTagEngine
{
    private string $cacheDirectory;

    public function __construct(string $cacheDirectory)
    {
        $this->cacheDirectory = rtrim($cacheDirectory, '/');
    }

    /**
     * Renders one template through compiled-tag cache.
     *
     * @param array<string, mixed> $data
     */
    public function renderFile(string $file, array $data): string
    {
        $compiledFile = $this->compiledTemplateFile($file);

        /** @var array<int, array<string, mixed>> $__rvn_scope */
        $__rvn_scope = [$data];
        $__rvn_tags = $this;
        extract($data, EXTR_SKIP);

        if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
            define('RAVEN_VIEW_RENDER_CONTEXT', true);
        }

        ob_start();
        include $compiledFile;

        return (string) ob_get_clean();
    }

    /**
     * Resolves one template tag path and returns printable output.
     *
     * @param array<int, array<string, mixed>> $scope
     */
    public function value(string $path, array $scope, bool $raw = false): string
    {
        $value = $this->resolvePath($path, $scope);
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
     * Resolves one truthy/falsy tag condition.
     *
     * @param array<int, array<string, mixed>> $scope
     */
    public function truthy(string $path, array $scope): bool
    {
        $value = $this->resolvePath($path, $scope);
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
     * Resolves one iterable path for `{each ...}` loops.
     *
     * @param array<int, array<string, mixed>> $scope
     * @return array<int|string, mixed>
     */
    public function iter(string $path, array $scope): array
    {
        $value = $this->resolvePath($path, $scope);
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<int, array<string, mixed>> $scope
     */
    private function resolvePath(string $path, array $scope): mixed
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
     * @param array<int, array<string, mixed>> $scope
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

    private function compiledTemplateFile(string $sourceFile): string
    {
        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new RuntimeException('Template source file is unreadable: ' . $sourceFile);
        }

        $this->ensureCacheDirectory();
        $mtime = @filemtime($sourceFile);
        $cacheKey = sha1($sourceFile . '|' . (string) ($mtime === false ? 0 : (int) $mtime));
        $compiledFile = $this->cacheDirectory . '/tag-template-' . $cacheKey . '.php';

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

    private function ensureCacheDirectory(): void
    {
        if (is_dir($this->cacheDirectory)) {
            return;
        }

        if (!@mkdir($this->cacheDirectory, 0700, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Failed to create template-tag cache directory: ' . $this->cacheDirectory);
        }
    }

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
