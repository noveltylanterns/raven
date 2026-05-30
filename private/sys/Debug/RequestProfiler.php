<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/RequestProfiler.php
 * Request-scoped profiler collector used by query and render instrumentation.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Collects in-memory request profiling data and optional custom outputs.
 */
final class RequestProfiler
{
    private static bool $enabled = false;
    private static float $requestStart = 0.0;
    private static string $scope = '';
    private static int $maxQueries = 300;
    /** @var array<int, array<string, mixed>> */
    private static array $queries = [];
    private static int $droppedQueries = 0;
    /** @var array<int, string>|null */
    private static ?array $renderTrace = null;
    /** @var array<string, RequestProfilerOutput> */
    private static array $outputs = [];

    /**
     * Resets request-profiler state for the active request scope.
     *
     * @param float $requestStart Request start timestamp from the entrypoint or web server.
     * @param string $scope Scope label such as `public` or `panel`.
     * @return void
     */
    public static function start(float $requestStart, string $scope): void
    {
        self::$requestStart = $requestStart > 0 ? $requestStart : microtime(true);
        self::$scope = strtolower(trim($scope));
        self::$queries = [];
        self::$droppedQueries = 0;
        self::$renderTrace = null;
    }

    /**
     * Enables request profiling and clamps the maximum retained query rows.
     *
     * @param int $maxQueries Maximum number of query rows to retain in memory.
     * @return void
     */
    public static function enable(int $maxQueries = 300): void
    {
        self::$enabled = true;
        self::$maxQueries = max(1, min(2000, $maxQueries));
    }

    /**
     * Disables profiling for the current request.
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Reports whether the current request is actively collecting profile data.
     *
     * @return bool True when request profiling is enabled for this request.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Stores one normalized SQL query record on the current request snapshot.
     *
     * @param string $connection Logical connection label such as `app` or `auth`.
     * @param string $mode Query execution mode reported by `QueryProfilerPdo`.
     * @param string $sql Raw SQL text for the executed statement.
     * @param array<int|string, mixed>|null $params Bound parameters captured for the statement.
     * @param float $durationMs Query duration in milliseconds.
     * @param bool $success Whether the statement completed successfully.
     * @param string|null $error Optional driver error text when the statement failed.
     * @return void
     */
    public static function recordQuery(
        string $connection,
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error = null
    ): void {
        // Fast-exit when profiling is off to keep hot query paths low overhead.
        if (!self::$enabled) {
            return;
        }

        $sql = trim($sql);
        // Ignore empty SQL fragments so snapshots only include executable statements.
        if ($sql === '') {
            return;
        }

        // Enforce an upper bound on retained rows and track how many records were dropped.
        if (count(self::$queries) >= self::$maxQueries) {
            self::$droppedQueries++;
            return;
        }

        $connection = strtolower(trim($connection));
        $mode = strtolower(trim($mode));

        self::$queries[] = [
            'connection' => $connection !== '' ? $connection : 'app',
            'mode' => $mode !== '' ? $mode : 'execute',
            'sql' => $sql,
            'params' => self::normalizeParams($params ?? []),
            'duration_ms' => max(0.0, round($durationMs, 3)),
            'success' => $success,
            'error' => $error !== null ? trim($error) : null,
        ];
    }

    /**
     * Captures the first render trace snapshot recorded during template rendering.
     *
     * @param array<int, array<string, mixed>> $trace
     * @return void
     */
    public static function captureRenderTrace(array $trace): void
    {
        // Capture only once per request and only while profiling is active.
        if (!self::$enabled || self::$renderTrace !== null) {
            return;
        }

        $lines = [];
        // Build compact file/function lines from stack frames for operator readability.
        foreach ($trace as $frame) {
            $function = trim((string) ($frame['function'] ?? ''));
            // Skip frames without callable names to avoid cluttering the trace output.
            if ($function === '') {
                continue;
            }

            $class = trim((string) ($frame['class'] ?? ''));
            $type = trim((string) ($frame['type'] ?? ''));
            $file = trim((string) ($frame['file'] ?? '[internal]'));
            $line = (int) ($frame['line'] ?? 0);

            $call = ($class !== '' ? ($class . $type) : '') . $function . '()';
            $location = $line > 0 ? ($file . ':' . $line) : $file;
            $lines[] = $location . ' - ' . $call;

            // Bound render-trace size so snapshots remain lightweight.
            if (count($lines) >= 80) {
                break;
            }
        }

        self::$renderTrace = $lines;
    }

    /**
     * Returns the full normalized request-profiler snapshot for the active request.
     *
     * @return array{
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
     * }
     */
    public static function snapshot(): array
    {
        $durationMs = max(0.0, round((microtime(true) - self::$requestStart) * 1000, 3));
        $queryTimeMs = 0.0;
        // Aggregate total SQL time across logged query rows for summary metrics.
        foreach (self::$queries as $query) {
            $queryTimeMs += (float) ($query['duration_ms'] ?? 0.0);
        }
        $queryLoggedCount = count(self::$queries);

        return [
            'enabled' => self::$enabled,
            'scope' => self::$scope,
            'request_start' => self::$requestStart,
            'duration_ms' => $durationMs,
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'query_count' => $queryLoggedCount + self::$droppedQueries,
            'query_logged_count' => $queryLoggedCount,
            'query_dropped_count' => self::$droppedQueries,
            'query_time_ms' => round($queryTimeMs, 3),
            'queries' => self::$queries,
            'render_trace' => self::$renderTrace ?? [],
        ];
    }

    /**
     * Registers one named custom request-profiler output renderer.
     *
     * @param RequestProfilerOutput $output Output renderer keyed by its `id()` value.
     * @return void
     */
    public static function registerOutput(RequestProfilerOutput $output): void
    {
        $id = strtolower(trim($output->id()));
        // Empty output ids are invalid because outputs are addressed by this key.
        if ($id === '') {
            return;
        }

        self::$outputs[$id] = $output;
    }

    /**
     * Reports whether one named custom output has been registered.
     *
     * @param string $id Requested output id.
     * @return bool True when a renderer exists for the normalized id.
     */
    public static function hasOutput(string $id): bool
    {
        $normalized = strtolower(trim($id));
        return isset(self::$outputs[$normalized]);
    }

    /**
     * Renders one custom output from the current request snapshot.
     *
     * @param string $id Requested output id.
     * @param array<string, mixed> $context
     * @return string Rendered output, or an empty string when no renderer matches.
     */
    public static function renderOutput(string $id, array $context = []): string
    {
        $normalized = strtolower(trim($id));
        // Return empty output for unknown ids instead of raising runtime warnings.
        if ($normalized === '' || !isset(self::$outputs[$normalized])) {
            return '';
        }

        return self::$outputs[$normalized]->render(self::snapshot(), $context);
    }

    /**
     * Renders every registered custom output from the current request snapshot.
     *
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public static function renderAllOutputs(array $context = []): array
    {
        $snapshot = self::snapshot();
        $rendered = [];
        // Render each registered output against one shared snapshot for consistency.
        foreach (self::$outputs as $id => $output) {
            $html = $output->render($snapshot, $context);
            // Skip empty fragments so callers only receive materialized outputs.
            if ($html === '') {
                continue;
            }

            $rendered[$id] = $html;
        }

        return $rendered;
    }

    /**
     * Removes every registered custom output renderer.
     *
     * @return void
     */
    public static function clearOutputs(): void
    {
        self::$outputs = [];
    }

    /**
     * Normalizes one bound-parameter payload into profiler-safe scalar representations.
     *
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    private static function normalizeParams(array $params): array
    {
        $normalized = [];
        // Normalize every bound parameter to predictable scalar/debug-safe placeholders.
        foreach ($params as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Normalizes one arbitrary bound value into a compact profiler-safe representation.
     *
     * @param mixed $value Raw bound parameter value.
     * @return mixed Normalized scalar/placeholder value suitable for profiler output.
     */
    private static function normalizeValue(mixed $value): mixed
    {
        // Primitive scalars are already safe and readable for profiler output.
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        // Collapse whitespace noise and cap long string payloads for compact display.
        if (is_string($value)) {
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            return self::truncateValue(trim($value));
        }

        // Encode arrays as JSON when possible so nested params stay visible in one cell.
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // Use encoded payload when valid; otherwise fall back to a generic array marker.
            if (is_string($encoded)) {
                return self::truncateValue($encoded);
            }

            return '[array]';
        }

        // Expose class identity for object params without serializing internal state.
        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * Truncates long scalar payloads so profiler output remains bounded and readable.
     *
     * @param string $value Raw scalar value.
     * @return string Possibly truncated scalar string.
     */
    private static function truncateValue(string $value): string
    {
        // Keep short values intact to avoid unnecessary noise in profiler rows.
        if (strlen($value) <= 400) {
            return $value;
        }

        return substr($value, 0, 400) . '…';
    }
}
