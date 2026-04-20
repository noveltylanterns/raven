<?php

/**
 * RAVEN CMS
 * ~/private/lib/Extra/Helpers.php
 * Shared helper functions for Raven CMS.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Keep these helpers side-effect-light for safe reuse across entrypoints.

declare(strict_types=1);

namespace Raven\Lib\Extra;

use Raven\Lib\Transport\Request;

/**
 * Escapes text for safe HTML output.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Returns request URI path without query string.
 *
 * Legacy wrapper retained while callers migrate to `Raven\Lib\Transport\Request::path()`.
 */
function request_path(): string
{
    return Request::path();
}
