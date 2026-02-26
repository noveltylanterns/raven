<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/View.php
 * Core framework component used by Raven CMS.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Core utilities are shared by both public and panel runtime flows.

declare(strict_types=1);

namespace Raven\Core;

use RuntimeException;

/**
 * Very small view renderer with optional layout wrapping.
 */
final class View
{
    /** Absolute base path that contains templates. */
    private string $viewsPath;

    public function __construct(string $viewsPath)
    {
        $this->viewsPath = rtrim($viewsPath, '/');
    }

    /**
     * Renders a template and optionally wraps it in a layout.
     *
     * @param array<string, mixed> $data Variables exposed to template scope.
     */
    public function render(string $template, array $data = [], ?string $layout = null): void
    {
        $templateFile = $this->resolve($template);
        $content = $this->renderFile($templateFile, $data);

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = $this->resolve($layout);

        // Pass template body to layout as `$content`.
        $layoutData = $data;
        $layoutData['content'] = $content;

        echo $this->renderFile($layoutFile, $layoutData);
    }

    /**
     * Resolves a logical template path (`home`) into an absolute file.
     */
    private function resolve(string $template): string
    {
        $path = $this->viewsPath . '/' . trim($template, '/') . '.php';

        if (!is_file($path)) {
            throw new RuntimeException("View template not found: {$template}");
        }

        return $path;
    }

    /**
     * Executes one template file in an isolated output buffer.
     *
     * @param array<string, mixed> $data
     */
    private function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);

        if (\Raven\Core\Debug\RequestProfiler::isEnabled()) {
            /** @var array<int, array<string, mixed>> $trace */
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80);
            \Raven\Core\Debug\RequestProfiler::captureRenderTrace($trace);
        }

        // Templates must only execute through Raven renderers, never as direct PHP endpoints.
        if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
            define('RAVEN_VIEW_RENDER_CONTEXT', true);
        }

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
