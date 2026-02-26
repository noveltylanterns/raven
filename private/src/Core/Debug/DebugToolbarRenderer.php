<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Debug/DebugToolbarRenderer.php
 * HTML renderer/injector for fixed-bottom debug toolbar output.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

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
     * } $settings
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
     * } $profile
     * @param array<string, mixed> $context
     */
    public static function render(array $settings, array $profile, array $context): string
    {
        // Fail closed: the renderer only emits markup when caller confirms
        // current user has system-configuration permission.
        if (!isset($context['can_manage_configuration']) || $context['can_manage_configuration'] !== true) {
            return '';
        }

        $queryCount = (int) ($profile['query_count'] ?? 0);
        $durationMs = (float) ($profile['duration_ms'] ?? 0.0);
        $queryTimeMs = (float) ($profile['query_time_ms'] ?? 0.0);
        $memoryPeakBytes = (int) ($profile['memory_peak_bytes'] ?? 0);
        $scope = (string) ($context['scope'] ?? (string) ($profile['scope'] ?? ''));
        $statusCode = (int) ($context['status_code'] ?? http_response_code());
        $requestMethod = strtoupper((string) ($context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        $requestPath = (string) ($context['request_path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        $hostname = (string) ($context['hostname'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
        $queryRows = is_array($profile['queries'] ?? null) ? $profile['queries'] : [];
        $traceRows = is_array($profile['render_trace'] ?? null) ? $profile['render_trace'] : [];

        $summaryLeft = sprintf(
            '%d queries | %.1fms total | %.1fms SQL | peak %s',
            $queryCount,
            $durationMs,
            $queryTimeMs,
            self::formatBytes($memoryPeakBytes)
        );
        $summaryRight = strtoupper($scope !== '' ? $scope : 'UNKNOWN') . ' | ' . $requestMethod . ' | HTTP ' . $statusCode;

        $sections = [];
        if (!empty($settings['show_benchmarks'])) {
            $sections[] = self::section(
                'Benchmarks',
                self::renderBenchmarks($profile, $context)
            );
        }
        if (!empty($settings['show_queries'])) {
            $sections[] = self::section(
                'SQL Queries',
                self::renderQueries($queryRows, (int) ($profile['query_dropped_count'] ?? 0))
            );
        }
        if (!empty($settings['show_stack_trace'])) {
            $sections[] = self::section(
                'Render Stack Trace',
                self::renderTrace($traceRows)
            );
        }
        if (!empty($settings['show_request'])) {
            $sections[] = self::section(
                'Request Data',
                self::renderRequestData()
            );
        }
        if (!empty($settings['show_environment'])) {
            $sections[] = self::section(
                'Environment',
                self::renderEnvironment($scope, $hostname, $requestPath)
            );
        }
        if ($sections === []) {
            $sections[] = self::section(
                'Profiler',
                '<p class="raven-debug-empty">No expanded sections are enabled. Enable checkboxes on the Debug settings page.</p>'
            );
        }

        return '<style>
#raven-debug-toolbar{position:fixed;left:0;right:0;bottom:0;z-index:2147483646;background:#000;color:#d7d7d7;font:12px/1.4 "Red Hat Mono",ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;box-shadow:0 -2px 18px rgba(0,0,0,.6)}
#raven-debug-toolbar *{box-sizing:border-box}
#raven-debug-toolbar .raven-debug-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 10px;border-top:1px solid #202020}
#raven-debug-toolbar .raven-debug-left{display:flex;align-items:center;gap:14px;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
#raven-debug-toolbar .raven-debug-left strong{color:#fff;font-weight:700}
#raven-debug-toolbar .raven-debug-right{display:flex;align-items:center;gap:14px;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
#raven-debug-toolbar .raven-debug-expand{appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid #484848;background:#111;color:#fff;border-radius:4px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer}
#raven-debug-toolbar .raven-debug-expand .bi{font-size:10px;line-height:1;vertical-align:middle}
#raven-debug-toolbar .raven-debug-expand:hover{background:#1c1c1c}
#raven-debug-toolbar .raven-debug-panel{display:none;height:max(25vh,333px);overflow:auto;background:#050505;border-top:1px solid #242424;padding:10px}
#raven-debug-toolbar.raven-debug-open .raven-debug-panel{display:block}
#raven-debug-toolbar .raven-debug-sections{display:grid;gap:10px}
#raven-debug-toolbar .raven-debug-section{border:1px solid #2a2a2a;background:#0b0b0b;border-radius:6px;overflow:hidden}
#raven-debug-toolbar .raven-debug-section h3{margin:0;padding:8px 10px;border-bottom:1px solid #1f1f1f;color:#fff;font-size:12px;letter-spacing:.02em}
#raven-debug-toolbar .raven-debug-section .raven-debug-body{padding:8px 10px}
#raven-debug-toolbar table{width:100%;border-collapse:collapse}
#raven-debug-toolbar th,#raven-debug-toolbar td{border:1px solid #2a2a2a;padding:5px 6px;vertical-align:top;text-align:left}
#raven-debug-toolbar th{background:#121212;color:#fff}
#raven-debug-toolbar code,#raven-debug-toolbar pre{font:11px/1.4 "Red Hat Mono",ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
#raven-debug-toolbar pre{margin:0;white-space:pre-wrap;word-break:break-word;background:#090909;border:1px solid #2a2a2a;border-radius:4px;padding:8px}
#raven-debug-toolbar .raven-debug-muted{color:#9a9a9a}
#raven-debug-toolbar .raven-debug-empty{margin:0;color:#bcbcbc}
</style>
<div id="raven-debug-toolbar" data-raven-debugger="1" data-raven-debug-open="0">
    <div class="raven-debug-bar">
        <div class="raven-debug-left">
            <strong>Output Profiler</strong>
            <button type="button" class="raven-debug-expand" data-raven-debug-toggle="1" aria-expanded="false">Expand<i class="bi bi-caret-up-fill" aria-hidden="true"></i></button>
        </div>
        <div class="raven-debug-right">
            <span>' . self::e($summaryLeft) . '</span>
            <span class="raven-debug-muted">' . self::e($summaryRight) . '</span>
        </div>
    </div>
    <div class="raven-debug-panel" aria-hidden="true">
        <div class="raven-debug-sections">
            ' . implode("\n", $sections) . '
        </div>
    </div>
</div>
<script>
(function(){
    var root=document.getElementById("raven-debug-toolbar");
    if(!root){return;}
    var toggle=root.querySelector("[data-raven-debug-toggle=\"1\"]");
    if(!(toggle instanceof HTMLButtonElement)){return;}
    var panel=root.querySelector(".raven-debug-panel");
    var expandLabel="Expand<i class=\"bi bi-caret-up-fill\" aria-hidden=\"true\"></i>";
    var collapseLabel="Collapse<i class=\"bi bi-caret-down-fill\" aria-hidden=\"true\"></i>";
    function setOpen(next){
        root.classList.toggle("raven-debug-open",next);
        toggle.setAttribute("aria-expanded",next?"true":"false");
        toggle.innerHTML=next?collapseLabel:expandLabel;
        if(panel){panel.setAttribute("aria-hidden",next?"false":"true");}
    }
    toggle.addEventListener("click",function(){ setOpen(!root.classList.contains("raven-debug-open")); });
})();
</script>';
    }

    public static function isHtmlResponseCandidate(string $body): bool
    {
        $statusCode = http_response_code();
        if ($statusCode >= 300 && $statusCode < 400) {
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

    public static function inject(string $body, string $toolbarHtml): string
    {
        $needle = '</body>';
        $offset = strripos($body, $needle);
        if ($offset === false) {
            return $body . $toolbarHtml;
        }

        return substr($body, 0, $offset) . $toolbarHtml . substr($body, $offset);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $context
     */
    private static function renderBenchmarks(array $profile, array $context): string
    {
        $rows = [
            ['Request Duration', number_format((float) ($profile['duration_ms'] ?? 0.0), 3) . ' ms'],
            ['SQL Time', number_format((float) ($profile['query_time_ms'] ?? 0.0), 3) . ' ms'],
            ['SQL Queries', (string) ((int) ($profile['query_count'] ?? 0))],
            ['Logged Query Rows', (string) ((int) ($profile['query_logged_count'] ?? 0))],
            ['Current Memory', self::formatBytes((int) ($profile['memory_usage_bytes'] ?? 0))],
            ['Peak Memory', self::formatBytes((int) ($profile['memory_peak_bytes'] ?? 0))],
            ['Response Code', (string) ((int) ($context['status_code'] ?? http_response_code()))],
        ];

        $html = '<table><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><th scope="row">' . self::e($row[0]) . '</th><td>' . self::e($row[1]) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $queries
     */
    private static function renderQueries(array $queries, int $droppedCount): string
    {
        if ($queries === []) {
            return '<p class="raven-debug-empty">No SQL queries were recorded in this request.</p>';
        }

        $html = '<div class="raven-debug-muted" style="margin-bottom:8px">Logged ' . self::e((string) count($queries)) . ' query row(s)';
        if ($droppedCount > 0) {
            $html .= '; dropped ' . self::e((string) $droppedCount) . ' due to profiler cap';
        }
        $html .= '.</div>';
        $html .= '<table><thead><tr><th>Connection</th><th>Mode</th><th>Duration</th><th>SQL</th><th>Bindings</th></tr></thead><tbody>';

        foreach ($queries as $query) {
            $bindings = $query['params'] ?? [];
            $bindingsText = is_array($bindings) && $bindings !== []
                ? self::prettyJson($bindings)
                : '[]';
            $sql = (string) ($query['sql'] ?? '');
            $duration = number_format((float) ($query['duration_ms'] ?? 0.0), 3) . ' ms';
            $connection = (string) ($query['connection'] ?? 'app');
            $mode = (string) ($query['mode'] ?? 'execute');
            $error = trim((string) ($query['error'] ?? ''));

            $html .= '<tr>'
                . '<td>' . self::e($connection) . '</td>'
                . '<td>' . self::e($mode) . '</td>'
                . '<td>' . self::e($duration) . '</td>'
                . '<td><pre>' . self::e($sql) . ($error !== '' ? ("\n\nERROR: " . self::e($error)) : '') . '</pre></td>'
                . '<td><pre>' . self::e($bindingsText) . '</pre></td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param array<int, string> $trace
     */
    private static function renderTrace(array $trace): string
    {
        if ($trace === []) {
            return '<p class="raven-debug-empty">No render stack trace snapshot was captured.</p>';
        }

        return '<pre>' . self::e(implode("\n", $trace)) . '</pre>';
    }

    private static function renderRequestData(): string
    {
        $payload = [
            '_GET' => self::sanitizeArray($_GET),
            '_POST' => self::sanitizeArray($_POST),
            '_FILES' => self::sanitizeArray(self::normalizeFiles($_FILES)),
            '_COOKIE' => self::sanitizeArray($_COOKIE),
            '_SERVER' => self::sanitizeServer($_SERVER),
        ];

        return '<pre>' . self::e(self::prettyJson($payload)) . '</pre>';
    }

    private static function renderEnvironment(string $scope, string $hostname, string $requestPath): string
    {
        $info = [
            'scope' => $scope,
            'hostname' => $hostname,
            'request_path' => $requestPath,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'timezone' => date_default_timezone_get(),
            'loaded_extensions_count' => count(get_loaded_extensions()),
            'included_files_count' => count(get_included_files()),
        ];

        return '<pre>' . self::e(self::prettyJson($info)) . '</pre>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="raven-debug-section"><h3>' . self::e($title) . '</h3><div class="raven-debug-body">' . $body . '</div></section>';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = (int) floor(log($bytes, 1024));
        $index = max(0, min(count($units) - 1, $index));
        $value = $bytes / (1024 ** $index);
        return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function prettyJson(array $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return $encoded;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function sanitizeArray(array $value, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['__truncated' => true];
        }

        $output = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            if (preg_match('/password|passwd|secret|token|authorization|cookie|csrf/', $keyText) === 1) {
                $output[$key] = '[redacted]';
                continue;
            }

            if (is_array($item)) {
                $output[$key] = self::sanitizeArray($item, $depth + 1);
                continue;
            }

            if (is_string($item)) {
                $item = trim($item);
                if (strlen($item) > 500) {
                    $item = substr($item, 0, 500) . '…';
                }
                $output[$key] = $item;
                continue;
            }

            if (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $output[$key] = $item;
                continue;
            }

            if (is_object($item)) {
                $output[$key] = '[object ' . $item::class . ']';
                continue;
            }

            $output[$key] = '[' . gettype($item) . ']';
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private static function sanitizeServer(array $server): array
    {
        $allowed = [
            'REQUEST_METHOD',
            'REQUEST_URI',
            'SCRIPT_NAME',
            'QUERY_STRING',
            'HTTP_HOST',
            'SERVER_NAME',
            'SERVER_ADDR',
            'SERVER_PORT',
            'REMOTE_ADDR',
            'HTTP_USER_AGENT',
            'HTTPS',
            'REQUEST_TIME_FLOAT',
        ];

        $filtered = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $server)) {
                continue;
            }

            $value = $server[$key];
            if (is_string($value)) {
                $value = trim($value);
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                continue;
            }

            $normalized[$key] = [
                'name' => $file['name'] ?? '',
                'type' => $file['type'] ?? '',
                'size' => $file['size'] ?? 0,
                'error' => $file['error'] ?? null,
            ];
        }

        return $normalized;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
