<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/LocalProfiler.php
 * Local runtime-environment snapshot helper for diagnostics tooling.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Collects local host/runtime diagnostics for debug and profiling surfaces.
 */
final class LocalProfiler
{
    /**
     * Returns a normalized local runtime environment snapshot.
     *
     * @param array<string, mixed>|null $server Optional server map; defaults to `$_SERVER`.
     * @return array{
     *   php_version: string,
     *   sapi: string,
     *   os: string,
     *   timezone: string,
     *   memory_limit: string,
     *   max_execution_time: string,
     *   loaded_extensions: array<int, string>,
     *   server_software: string,
     *   document_root: string,
     *   request_method: string
     * }
     */
    public function snapshot(?array $server = null): array
    {
        $serverMap = $server ?? $_SERVER;

        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'timezone' => (string) date_default_timezone_get(),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'loaded_extensions' => $extensions,
            'server_software' => trim((string) ($serverMap['SERVER_SOFTWARE'] ?? '')),
            'document_root' => trim((string) ($serverMap['DOCUMENT_ROOT'] ?? '')),
            'request_method' => trim((string) ($serverMap['REQUEST_METHOD'] ?? '')),
        ];
    }
}

