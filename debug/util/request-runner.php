<?php

/**
 * RAVEN CMS
 * ~/debug/util/request-runner.php
 * Internal CLI request executor for front-controller smoke tests.
 * Docs: https://raven.lanterns.io
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

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fail('This script must run in CLI/phpdbg mode.');
}

if ($argc < 2) {
    fail('Usage: php debug/util/request-runner.php <payload.json>');
}

$payloadPath = (string) $argv[1];
$payloadRaw = @file_get_contents($payloadPath);
if (!is_string($payloadRaw) || trim($payloadRaw) === '') {
    fail('Unable to read payload file.');
}

/** @var mixed $payloadDecoded */
$payloadDecoded = json_decode($payloadRaw, true);
if (!is_array($payloadDecoded)) {
    fail('Payload is not valid JSON.');
}

$script = trim((string) ($payloadDecoded['script'] ?? ''));
$method = strtoupper(trim((string) ($payloadDecoded['method'] ?? 'GET')));
$uri = (string) ($payloadDecoded['uri'] ?? '/');
$host = trim((string) ($payloadDecoded['host'] ?? 'localhost'));
$outputPath = trim((string) ($payloadDecoded['output'] ?? ''));

/** @var mixed $postRaw */
$postRaw = $payloadDecoded['post'] ?? [];
/** @var mixed $cookiesRaw */
$cookiesRaw = $payloadDecoded['cookies'] ?? [];

if ($script === '' || !is_file($script)) {
    fail('Payload missing valid script path.');
}
if ($outputPath === '') {
    fail('Payload missing output file path.');
}
if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
    $method = 'GET';
}
if ($host === '') {
    $host = 'localhost';
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

require $script;
