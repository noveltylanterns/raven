<?php

declare(strict_types=1);

namespace Raven\Core\Routing;

/**
 * Immutable routing request contract.
 */
final class RouteRequest
{
    private string $method;
    private string $path;

    public function __construct(string $method, string $path)
    {
        $this->method = strtoupper(trim($method));
        $this->path = self::normalizePath($path);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public static function normalizePath(string $path): string
    {
        $trimmed = '/' . trim($path, '/');
        return $trimmed === '//' ? '/' : $trimmed;
    }
}

