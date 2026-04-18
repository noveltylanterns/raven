<?php

declare(strict_types=1);

namespace Raven\Lib\Transport;

/**
 * Thin HTTP response helper for redirect/json/common headers.
 */
final class Response
{
    public static function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    /**
     * @param array<string, mixed> $payload
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

    public static function applyNoStoreHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
}
