<?php

/**
 * RAVEN CMS
 * ~/private/lib/Transport/Response.php
 * Thin shared response helper for JSON and common cache headers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Thin HTTP response helper for redirect/json/common headers.
 */
final class Response
{
    /**
     * Applies the shared no-store cache headers Raven uses for sensitive responses.
     *
     * @return void
     */
    public static function applyNoStoreHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Emits a JSON response payload with optional no-store cache headers.
     *
     * @param array<string, mixed> $payload
     * @param int $status HTTP status code to emit for the JSON response.
     * @param bool $noStore Whether the response should opt out of client/proxy caching.
     * @return void
     */
    public static function json(array $payload, int $status = 200, bool $noStore = true): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        if ($noStore) {
            self::applyNoStoreHeaders();
        }

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            echo is_string($encoded) ? $encoded : '{}';
        } catch (\Throwable) {
            echo '{}';
        }
    }
}
