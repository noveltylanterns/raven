<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Support/Helpers.php
 * Shared helper functions for Raven CMS.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Keep these helpers side-effect-light for safe reuse across entrypoints.

declare(strict_types=1);

namespace Raven\Core\Support;

/**
 * Escapes text for safe HTML output.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Sends HTTP redirect and immediately stops script execution.
 */
function redirect(string $to, int $status = 302): never
{
    header('Location: ' . $to, true, $status);
    exit;
}

/**
 * Returns request URI path without query string.
 */
function request_path(): string
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return $path === '' ? '/' : $path;
}
