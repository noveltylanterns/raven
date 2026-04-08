<?php

/**
 * RAVEN CMS
 * ~/private/lib/Diagnostics/Toolbar/DebugToolbarRenderer.php
 * HTML renderer/injector for fixed-bottom debug toolbar output.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Diagnostics\Toolbar;

/**
 * Produces the debug-toolbar UI and appends it into HTML responses.
 */
final class DebugToolbarRenderer
{
    /**
     * @param array{
     *   show_benchmarks: bool,
     *   show_queries: bool,
     *   show_stack_trace: bool,
     *   show_request: bool,
     *   show_environment: bool
     * } $settings Toolbar visibility toggles for the current response.
     * @param array{
     *   enabled: bool,
     *   scope: string,
     *   request_start: float,
     *   duration_ms: float,
     *   memory_usage_bytes: int,
     *   memory_peak_bytes: int,
     *   query_count: int,
     *   query_logged_count: int,
     *   query_dropped_count: int,
     *   query_time_ms: float,
     *   queries: array<int, array<string, mixed>>,
     *   render_trace: array<int, string>
     * } $profile Captured request/profile snapshot.
     * @param array<string, mixed> $context Response/request metadata shown in the toolbar.
     * @return string Rendered toolbar HTML for injection.
     */
    public static function render(array $settings, array $profile, array $context): string
    {
        return DebugToolbarMarkupBuilder::render($settings, $profile, $context);
    }

    /**
     * Returns true when the current response body can safely receive toolbar markup.
     *
     * @param string $body Current buffered response body.
     * @return bool True when the response appears to be HTML and not a redirect.
     */
    public static function isHtmlResponseCandidate(string $body): bool
    {
        $statusCode = http_response_code();
        if (is_int($statusCode) && $statusCode >= 300 && $statusCode < 400) {
            return false;
        }

        if ($body === '') {
            return false;
        }

        $contentType = '';
        foreach (headers_list() as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') !== 0) {
                continue;
            }

            $contentType = strtolower(trim(substr($headerLine, strlen('Content-Type:'))));
            break;
        }

        if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
            return false;
        }

        return true;
    }

    /**
     * Injects toolbar HTML before the closing body tag when possible.
     *
     * @param string $body Current buffered response body.
     * @param string $toolbarHtml Toolbar markup to append into the response.
     * @return string Response body with toolbar markup injected.
     */
    public static function inject(string $body, string $toolbarHtml): string
    {
        $needle = '</body>';
        $offset = strripos($body, $needle);
        if ($offset === false) {
            return $body . $toolbarHtml;
        }

        return substr($body, 0, $offset) . $toolbarHtml . substr($body, $offset);
    }
}
