<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/DebugToolbarResponseHook.php
 * Shared debug-toolbar response hook for web entrypoints.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing;

use Raven\Lib\Diagnostics\RequestProfiler;
use Raven\Core\Debug\DebugToolbarRenderer;

/**
 * Arms the shared debug-toolbar response hook for one web scope.
 */
final class DebugToolbarResponseHook
{
    /**
     * Starts request profiling and installs one response wrapper when toolbar rendering is allowed.
     *
     * The entrypoint still owns scope-specific gating decisions such as auth-helper path suppression
     * and whether the current scope should expose the toolbar at all. This helper only owns the
     * shared profiler start + HTML injection plumbing once that scope decision has already been made.
     *
     * @param array{
     *   show_benchmarks: bool,
     *   show_queries: bool,
     *   show_stack_trace: bool,
     *   show_request: bool,
     *   show_environment: bool
     * } $settings Shared toolbar display settings for the active request.
     * @param string $scope Request scope label (`public` or `panel`) for profiler output.
     * @param string $requestMethod Active HTTP method.
     * @param string $requestPath Scope-local request path shown in the toolbar.
     * @param bool $enabledForScope Whether the current scope is configured to show the toolbar.
     * @param callable(): bool $canRenderToolbar Scope-specific callback that re-checks current auth/path rules.
     * @return void
     */
    public static function arm(
        array $settings,
        string $scope,
        string $requestMethod,
        string $requestPath,
        bool $enabledForScope,
        callable $canRenderToolbar
    ): void {
        if ($requestMethod !== 'GET' || !$enabledForScope || !$canRenderToolbar()) {
            return;
        }

        RequestProfiler::start((float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), $scope);
        RequestProfiler::enable();

        ob_start(static function (string $body) use ($settings, $scope, $requestMethod, $requestPath, $canRenderToolbar): string {
            if (!RequestProfiler::isEnabled() || !DebugToolbarRenderer::isHtmlResponseCandidate($body)) {
                return $body;
            }

            // Defense-in-depth: always re-check current auth/path permission before rendering.
            if (!$canRenderToolbar()) {
                return $body;
            }

            $toolbarHtml = DebugToolbarRenderer::render(
                [
                    'show_benchmarks' => (bool) ($settings['show_benchmarks'] ?? true),
                    'show_queries' => (bool) ($settings['show_queries'] ?? true),
                    'show_stack_trace' => (bool) ($settings['show_stack_trace'] ?? true),
                    'show_request' => (bool) ($settings['show_request'] ?? true),
                    'show_environment' => (bool) ($settings['show_environment'] ?? true),
                ],
                RequestProfiler::snapshot(),
                [
                    'scope' => $scope,
                    'can_manage_configuration' => true,
                    'status_code' => http_response_code(),
                    'request_method' => $requestMethod,
                    'request_path' => $requestPath,
                    'hostname' => (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')),
                ]
            );

            if ($toolbarHtml === '') {
                return $body;
            }

            return DebugToolbarRenderer::inject($body, $toolbarHtml);
        });
    }
}
