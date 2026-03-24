<?php

declare(strict_types=1);

namespace Raven\Lib\Diagnostics;

/**
 * In-memory request profiler collector with pluggable render outputs.
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
    /** @var array<string, ProfilerOutputInterface> */
    private static array $outputs = [];

    public static function start(float $requestStart, string $scope): void
    {
        self::$requestStart = $requestStart > 0 ? $requestStart : microtime(true);
        self::$scope = strtolower(trim($scope));
        self::$queries = [];
        self::$droppedQueries = 0;
        self::$renderTrace = null;
    }

    public static function enable(int $maxQueries = 300): void
    {
        self::$enabled = true;
        self::$maxQueries = max(1, min(2000, $maxQueries));
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function recordQuery(
        string $connection,
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error = null
    ): void {
        if (!self::$enabled) {
            return;
        }

        $sql = trim($sql);
        if ($sql === '') {
            return;
        }

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
     * @param array<int, array<string, mixed>> $trace
     */
    public static function captureRenderTrace(array $trace): void
    {
        if (!self::$enabled || self::$renderTrace !== null) {
            return;
        }

        $lines = [];
        foreach ($trace as $frame) {
            $function = trim((string) ($frame['function'] ?? ''));
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

            if (count($lines) >= 80) {
                break;
            }
        }

        self::$renderTrace = $lines;
    }

    /**
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

    public static function registerOutput(ProfilerOutputInterface $output): void
    {
        $id = strtolower(trim($output->id()));
        if ($id === '') {
            return;
        }

        self::$outputs[$id] = $output;
    }

    public static function hasOutput(string $id): bool
    {
        $normalized = strtolower(trim($id));
        return isset(self::$outputs[$normalized]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function renderOutput(string $id, array $context = []): string
    {
        $normalized = strtolower(trim($id));
        if ($normalized === '' || !isset(self::$outputs[$normalized])) {
            return '';
        }

        return self::$outputs[$normalized]->render(self::snapshot(), $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public static function renderAllOutputs(array $context = []): array
    {
        $snapshot = self::snapshot();
        $rendered = [];
        foreach (self::$outputs as $id => $output) {
            $html = $output->render($snapshot, $context);
            if ($html === '') {
                continue;
            }

            $rendered[$id] = $html;
        }

        return $rendered;
    }

    public static function clearOutputs(): void
    {
        self::$outputs = [];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    private static function normalizeParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            return self::truncateValue(trim($value));
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                return self::truncateValue($encoded);
            }

            return '[array]';
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        return '[' . gettype($value) . ']';
    }

    private static function truncateValue(string $value): string
    {
        if (strlen($value) <= 400) {
            return $value;
        }

        return substr($value, 0, 400) . '…';
    }
}
