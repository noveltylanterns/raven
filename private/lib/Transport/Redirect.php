<?php

/**
 * RAVEN CMS
 * ~/private/lib/Transport/Redirect.php
 * Shared HTTP redirect dispatch helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Shared HTTP redirect dispatch primitive.
 */
final class Redirect
{
    /**
     * Sends an HTTP redirect response and immediately stops execution.
     *
     * @param string $to Redirect target URL or root-relative path.
     * @param int $status HTTP redirect status code to emit with the Location header.
     * @return never Method never returns because redirect responses terminate execution.
     */
    public static function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . $to, true, $status);
        exit;
    }
}
