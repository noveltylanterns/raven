<?php

/**
 * RAVEN CMS
 * ~/debug/util/request-runner.php
 * Internal CLI request executor for front-controller smoke tests.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

/**
 * Writes one standardized usage failure to stderr and exits.
 */
function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(2);
}

/**
 * Resolves one runner path and rejects anything outside Raven's physical root.
 *
 * @param string $candidate User-supplied absolute or Raven-relative path.
 * @param string $projectRoot Canonical Raven project root.
 * @param bool $mustBeFile Whether the final path must already be a regular file.
 * @return string|null Canonical or root-contained path, or null when unsafe.
 */
function resolveRunnerPath(string $candidate, string $projectRoot, bool $mustBeFile): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    $path = str_starts_with($candidate, DIRECTORY_SEPARATOR)
        ? $candidate
        : $projectRoot . '/' . ltrim($candidate, '/\\');

    try {
        \Raven\Lib\Security\SymlinkGuard::assertSymlinkFreePath($path, 'debug runner path');
    } catch (\Throwable) {
        return null;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
    if ($mustBeFile) {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $resolved);
        return ($normalized === rtrim($rootPrefix, '/') || str_starts_with($normalized, $rootPrefix))
            ? $resolved
            : null;
    }

    $parent = realpath(dirname($path));
    if ($parent === false || !is_dir($parent)) {
        return null;
    }

    $normalizedParent = str_replace('\\', '/', $parent);
    if ($normalizedParent !== rtrim($rootPrefix, '/') && !str_starts_with($normalizedParent, $rootPrefix)) {
        return null;
    }

    $resolved = realpath($path);
    if ($resolved !== false) {
        if (!is_file($resolved)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $resolved);
        if ($normalized !== rtrim($rootPrefix, '/') && !str_starts_with($normalized, $rootPrefix)) {
            return null;
        }
        return $resolved;
    }

    return rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
}

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fail('This script must run in CLI/phpdbg mode.');
}

if ($argc < 2) {
    fail('Usage: php debug/util/request-runner.php <payload.json>');
}

$payloadPath = (string) $argv[1];
$projectRoot = realpath(dirname(__DIR__, 2));
if ($projectRoot === false || !is_dir($projectRoot)) {
    fail('Unable to resolve Raven project root.');
}

require_once $projectRoot . '/private/lib/Security/SymlinkGuard.php';
$resolvedPayloadPath = resolveRunnerPath($payloadPath, $projectRoot, true);
if ($resolvedPayloadPath === null) {
    fail('Payload file must resolve to a regular file within the Raven project root.');
}

$payloadRaw = @file_get_contents($resolvedPayloadPath);
if (!is_string($payloadRaw) || trim($payloadRaw) === '') {
    fail('Unable to read payload file.');
}

/** @var mixed $payloadDecoded */
$payloadDecoded = json_decode($payloadRaw, true);
if (!is_array($payloadDecoded)) {
    fail('Payload is not valid JSON.');
}

$scriptCandidate = trim((string) ($payloadDecoded['script'] ?? ''));
$method = strtoupper(trim((string) ($payloadDecoded['method'] ?? 'GET')));
$uri = (string) ($payloadDecoded['uri'] ?? '/');
$host = trim((string) ($payloadDecoded['host'] ?? 'localhost'));
$outputPath = resolveRunnerPath((string) ($payloadDecoded['output'] ?? ''), $projectRoot, false);

/** @var mixed $postRaw */
$postRaw = $payloadDecoded['post'] ?? [];
/** @var mixed $cookiesRaw */
$cookiesRaw = $payloadDecoded['cookies'] ?? [];

if ($outputPath === null) {
    fail('Payload output file must resolve inside the Raven project root.');
}
if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
    $method = 'GET';
}
if ($host === '') {
    $host = 'localhost';
}

// Resolve relative fixtures from Raven's root and reject symlinks escaping it.
$resolvedScript = resolveRunnerPath($scriptCandidate, $projectRoot, true);
if ($resolvedScript === null) {
    fail('Payload script must resolve to a PHP file within the Raven project root.');
}

$queryString = (string) parse_url($uri, PHP_URL_QUERY);
$pathOnly = (string) parse_url($uri, PHP_URL_PATH);
if ($pathOnly === '') {
    $pathOnly = '/';
}

$_GET = [];
if ($queryString !== '') {
    parse_str($queryString, $_GET);
}

$_POST = [];
if ($method === 'POST' && is_array($postRaw)) {
    $_POST = $postRaw;
}

$_COOKIE = [];
if (is_array($cookiesRaw)) {
    foreach ($cookiesRaw as $name => $value) {
        if (!is_string($name)) {
            continue;
        }

        $_COOKIE[$name] = is_scalar($value) ? (string) $value : '';
    }
}

$_SERVER = [
    'REQUEST_METHOD' => $method,
    'REQUEST_URI' => $uri,
    'QUERY_STRING' => $queryString,
    'HTTP_HOST' => $host,
    'SERVER_NAME' => $host,
    'SERVER_PORT' => '80',
    'HTTPS' => '',
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_USER_AGENT' => 'RavenSmoke/1.0',
    'SCRIPT_NAME' => $pathOnly,
    'DOCUMENT_ROOT' => dirname(__DIR__, 2),
];

$_REQUEST = $_GET;
if ($method === 'POST') {
    $_REQUEST = array_merge($_REQUEST, $_POST);
}

/**
 * Persists request result metadata so parent smoke runner can assert behavior.
 */
$writeResult = static function () use ($outputPath): void {
    $status = http_response_code();
    if (!is_int($status) || $status < 100) {
        $status = 200;
    }

    $body = '';
    if (ob_get_level() > 0) {
        // Flush nested output handlers into the runner's root buffer so
        // callback-based transforms (for example output profiler injection)
        // are applied before we capture response body text.
        while (ob_get_level() > 1) {
            ob_end_flush();
        }

        $body = (string) ob_get_contents();
    }

    $sessionStatus = session_status();
    $sessionId = '';
    if ($sessionStatus === PHP_SESSION_ACTIVE) {
        $sessionId = session_id();
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $payload = [
        'status' => $status,
        'body' => $body,
        'session_status' => $sessionStatus,
        'session_id' => $sessionId,
    ];

    @file_put_contents($outputPath, json_encode($payload, JSON_UNESCAPED_SLASHES));
};

ob_start();
register_shutdown_function($writeResult);

require $resolvedScript;
