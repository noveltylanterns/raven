<?php

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Builds debug toolbar HTML markup from normalized profiler payloads.
 */
final class DebugToolbarMarkupBuilder
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

        $summaryQueries = sprintf('%d queries', $queryCount);
        $summaryDuration = sprintf('%.1fms total', $durationMs);
        $summarySql = sprintf('%.1fms SQL', $queryTimeMs);
        $summaryPeak = 'peak ' . self::formatBytes($memoryPeakBytes);
        $summaryScope = strtoupper($scope !== '' ? $scope : 'UNKNOWN');
        $summaryMethod = $requestMethod;
        $summaryStatus = 'HTTP ' . $statusCode;

        $sections = [];
        self::appendSection($sections, $settings, 'show_benchmarks', 'Benchmarks', static fn (): string => self::renderBenchmarks($profile, $context));
        self::appendSection($sections, $settings, 'show_queries', 'SQL Queries', static fn (): string => self::renderQueries($queryRows, (int) ($profile['query_dropped_count'] ?? 0)));
        self::appendSection($sections, $settings, 'show_stack_trace', 'Render Stack Trace', static fn (): string => self::renderTrace($traceRows));
        self::appendSection($sections, $settings, 'show_request', 'Request Data', static fn (): string => self::renderRequestData());
        self::appendSection($sections, $settings, 'show_environment', 'Environment', static fn (): string => self::renderEnvironment($scope, $hostname, $requestPath));
        if ($sections === []) {
            $sections[] = self::section(
                'Profiler',
                '<p class="rvnd-empty">No expanded sections are enabled. Enable checkboxes on the Debug settings page.</p>'
            );
        }

        return '<style>
#rvnd{position:fixed;left:0;right:0;bottom:0;z-index:2147483646;background:#000;color:#d7d7d7;font:12px/1.4 "Red Hat Mono",ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;box-shadow:0 -2px 18px rgba(0,0,0,.6)}
#rvnd *{box-sizing:border-box}
#rvnd #rvnd-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 10px;border-top:1px solid #202020}
#rvnd .rvnd-left{display:flex;align-items:center;gap:14px;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
#rvnd .rvnd-title{color:#fff;font-weight:700}
#rvnd .rvnd-left strong{color:#fff;font-weight:700}
#rvnd .rvnd-right{display:flex;align-items:center;gap:0;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#fff}
#rvnd .rvnd-right .rvnd-muted{color:#fff}
#rvnd .rvnd-right > span + span::before{content:"|";display:inline-block;margin-right:5px;color:#fff}
#rvnd .rvnd-right > span + span{margin-left:5px}
#rvnd .rvnd-expand{appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid #484848;background:#111;color:#fff;border-radius:4px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer}
#rvnd .rvnd-expand .rvnd-caret{display:inline-block;min-width:10px;text-align:center;vertical-align:middle;font-size:10px;line-height:1}
#rvnd .rvnd-expand:hover{background:#1c1c1c}
#rvnd #rvnd-inside{display:none;height:max(25vh,333px);overflow:auto;background:#050505;border-top:1px solid #242424;padding:10px}
#rvnd.rvnd-open #rvnd-inside{display:block}
#rvnd .rvnd-sections{display:grid;gap:10px}
#rvnd .rvnd-section{border:1px solid #2a2a2a;background:#0b0b0b;border-radius:6px;overflow:hidden}
#rvnd .rvnd-section h3{margin:0;padding:8px 10px;border-bottom:1px solid #1f1f1f;color:#fff;font-size:12px;letter-spacing:.02em}
#rvnd .rvnd-section .rvnd-body{padding:8px 10px}
#rvnd table{width:100%;border-collapse:collapse}
#rvnd th,#rvnd td{border:1px solid #2a2a2a;padding:5px 6px;vertical-align:top;text-align:left}
#rvnd th{background:#121212;color:#fff}
#rvnd code,#rvnd pre{font:11px/1.4 "Red Hat Mono",ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
#rvnd pre{margin:0;white-space:pre-wrap;word-break:break-word;background:#090909;border:1px solid #2a2a2a;border-radius:4px;padding:8px}
#rvnd .rvnd-muted{color:#9a9a9a}
#rvnd .rvnd-empty{margin:0;color:#bcbcbc}
@media (max-width:767.98px){
    #rvnd .rvnd-title{display:none}
    #rvnd .rvnd-expand{padding:3px 7px}
    #rvnd:not(.rvnd-open) .rvnd-summary-scope{display:none !important}
}
@media (max-width:575.98px){
    #rvnd .rvnd-expand-label{display:none}
    #rvnd:not(.rvnd-open) .rvnd-summary-peak,
    #rvnd:not(.rvnd-open) .rvnd-summary-scope{display:none !important}
}
</style>
<div id="rvnd" data-rvn-debugger="1" data-rvn-debug-open="0">
    <div id="rvnd-bar">
        <div class="rvnd-left">
            <strong class="rvnd-title">Output Profiler</strong>
            <button type="button" class="rvnd-expand" data-rvn-debug-toggle="1" aria-expanded="false"><span class="rvnd-expand-label">Expand</span><span class="rvnd-caret" aria-hidden="true">^</span></button>
        </div>
        <div class="rvnd-right">
            <span class="rvnd-summary-queries">' . self::e($summaryQueries) . '</span>
            <span class="rvnd-summary-duration">' . self::e($summaryDuration) . '</span>
            <span class="rvnd-summary-sql">' . self::e($summarySql) . '</span>
            <span class="rvnd-summary-peak">' . self::e($summaryPeak) . '</span>
            <span class="rvnd-summary-scope">' . self::e($summaryScope) . '</span>
            <span class="rvnd-summary-method">' . self::e($summaryMethod) . '</span>
            <span class="rvnd-summary-status">' . self::e($summaryStatus) . '</span>
        </div>
    </div>
    <div id="rvnd-inside" aria-hidden="true">
        <div class="rvnd-sections">
            ' . implode("\n", $sections) . '
        </div>
    </div>
</div>
<script>
(function(){
    var root=document.getElementById("rvnd");
    if(!root){return;}
    var toggle=root.querySelector("[data-rvn-debug-toggle=\"1\"]");
    if(!(toggle instanceof HTMLButtonElement)){return;}
    var panel=root.querySelector("#rvnd-inside");
    var expandLabel="<span class=\"rvnd-expand-label\">Expand</span><span class=\"rvnd-caret\" aria-hidden=\"true\">^</span>";
    var collapseLabel="<span class=\"rvnd-expand-label\">Collapse</span><span class=\"rvnd-caret\" aria-hidden=\"true\">v</span>";
    function setOpen(next){
        root.classList.toggle("rvnd-open",next);
        toggle.setAttribute("aria-expanded",next?"true":"false");
        toggle.innerHTML=next?collapseLabel:expandLabel;
        if(panel){panel.setAttribute("aria-hidden",next?"false":"true");}
    }
    toggle.addEventListener("click",function(){ setOpen(!root.classList.contains("rvnd-open")); });
})();
</script>';
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
            return '<p class="rvnd-empty">No SQL queries were recorded in this request.</p>';
        }

        $html = '<div class="rvnd-muted" style="margin-bottom:8px">Logged ' . self::e((string) count($queries)) . ' query row(s)';
        if ($droppedCount > 0) {
            $html .= '; dropped ' . self::e((string) $droppedCount) . ' due to profiler cap';
        }
        $html .= '.</div>';
        $html .= '<table><thead><tr><th>Connection</th><th>Mode</th><th>Duration</th><th>SQL</th><th>Bindings</th></tr></thead><tbody>';

        foreach ($queries as $query) {
            $bindings = $query['params'] ?? [];
            $bindingsText = is_array($bindings) && $bindings !== []
                ? DebugToolbarDataSanitizer::prettyJson($bindings)
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
            return '<p class="rvnd-empty">No render stack trace snapshot was captured.</p>';
        }

        return '<pre>' . self::e(implode("\n", $trace)) . '</pre>';
    }

    private static function renderRequestData(): string
    {
        $payload = [
            '_GET' => DebugToolbarDataSanitizer::sanitizeArray($_GET),
            '_POST' => DebugToolbarDataSanitizer::sanitizeArray($_POST),
            '_FILES' => DebugToolbarDataSanitizer::sanitizeArray(DebugToolbarDataSanitizer::normalizeFiles($_FILES)),
            '_COOKIE' => DebugToolbarDataSanitizer::sanitizeArray($_COOKIE),
            '_SERVER' => DebugToolbarDataSanitizer::sanitizeServer($_SERVER),
        ];

        return '<pre>' . self::e(DebugToolbarDataSanitizer::prettyJson($payload)) . '</pre>';
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

        return '<pre>' . self::e(DebugToolbarDataSanitizer::prettyJson($info)) . '</pre>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="rvnd-section"><h3>' . self::e($title) . '</h3><div class="rvnd-body">' . $body . '</div></section>';
    }

    private static function appendSection(
        array &$sections,
        array $settings,
        string $flag,
        string $title,
        callable $render
    ): void {
        if (!empty($settings[$flag])) {
            $sections[] = self::section($title, (string) $render());
        }
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

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
