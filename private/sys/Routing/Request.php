<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Request.php
 * Immutable routing dispatch contract — method + normalized path passed to the router.
 * Not to be confused with Raven\Lib\Transport\Request, which reads HTTP server environment.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing;

/**
 * Immutable routing request contract.
 */
final class Request
{
    private string $method;
    private string $path;

    /**
     * @param string $method HTTP method such as `GET` or `POST`.
     * @param string $path   Raw request path.
     */
    public function __construct(string $method, string $path)
    {
        $this->method = strtoupper(trim($method));
        $this->path = self::normalizePath($path);
    }

    /**
     * Returns the normalized HTTP method for this request.
     *
     * @return string Uppercase HTTP method string.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Returns the normalized request path for this request.
     *
     * @return string Path string normalized to a leading slash.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Normalizes a raw path string to a leading-slash form.
     *
     * @param string $path Raw path value.
     * @return string Normalized path with a leading slash.
     */
    public static function normalizePath(string $path): string
    {
        $trimmed = '/' . trim($path, '/');
        return $trimmed === '//' ? '/' : $trimmed;
    }
}
