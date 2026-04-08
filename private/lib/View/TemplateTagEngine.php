<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/TemplateTagEngine.php
 * Public-template brace-tag compiler and renderer.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\View;

require_once dirname(__DIR__, 2) . '/lib/View/TemplateTagPathResolver.php';
require_once dirname(__DIR__, 2) . '/lib/View/TemplateTagCompiler.php';

use Raven\Lib\View\TemplateTagCompiler;
use Raven\Lib\View\TemplateTagPathResolver;
use RuntimeException;

use function Raven\Lib\Support\e;

/**
 * Compiles EE-style brace tags into PHP once, then renders from cache.
 */
final class TemplateTagEngine
{
    private string $cacheDirectory;
    private TemplateTagPathResolver $paths;
    private TemplateTagCompiler $compiler;

    public function __construct(
        string $cacheDirectory,
        ?TemplateTagPathResolver $paths = null,
        ?TemplateTagCompiler $compiler = null
    ) {
        $this->cacheDirectory = rtrim($cacheDirectory, '/');
        $this->paths = $paths ?? new TemplateTagPathResolver();
        $this->compiler = $compiler ?? new TemplateTagCompiler();
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
        $value = $this->paths->resolve($path, $scope);
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
        return $this->paths->truthy($path, $scope);
    }

    /**
     * Evaluates a comparison expression for `{if path op value}` template tags.
     *
     * @param string $path Tag path to resolve (e.g. 'page:id').
     * @param string $operator Comparison operator: ==, !=, <, >, <=, >=.
     * @param string $rhs Right-hand side literal from the compiled template.
     * @param array<int, array<string, mixed>> $scope Template scope stack.
     * @return bool True when the comparison holds.
     */
    public function compare(string $path, string $operator, string $rhs, array $scope): bool
    {
        return $this->paths->compare($path, $operator, $rhs, $scope);
    }

    /**
     * Resolves one iterable path for `{each ...}` loops.
     *
     * @param array<int, array<string, mixed>> $scope
     * @return array<int|string, mixed>
     */
    public function iter(string $path, array $scope): array
    {
        return $this->paths->iter($path, $scope);
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

        $compiledSource = $this->compiler->compileTemplate($source);
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
}
