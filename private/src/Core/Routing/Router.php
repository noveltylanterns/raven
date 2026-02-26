<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Routing/Router.php
 * Core router for path-to-handler dispatch.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Keep routing deterministic by matching normalized paths and methods only.

declare(strict_types=1);

namespace Raven\Core\Routing;

/**
 * Minimal path router supporting `{param}` placeholders.
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    /**
     * Registers one route for one HTTP method.
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $this->compilePattern($pattern),
            'handler' => $handler,
        ];
    }

    /**
     * Dispatches route and invokes its handler when matched.
     *
     * Returns true when a route handled the request, otherwise false.
     */
    public function dispatch(string $method, string $path): bool
    {
        $normalizedMethod = strtoupper($method);
        $normalizedPath = $this->normalizePath($path);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $normalizedMethod) {
                continue;
            }

            if (!preg_match($route['regex'], $normalizedPath, $matches)) {
                continue;
            }

            // Keep only named placeholders from regex captures.
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            ($route['handler'])($params);
            return true;
        }

        return false;
    }

    /**
     * Converts a pattern like `/pages/edit/{id}` into a strict regex.
     */
    private function compilePattern(string $pattern): string
    {
        $normalized = $this->normalizePath($pattern);

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $normalized
        );

        return '#^' . $regex . '$#';
    }

    /**
     * Normalizes incoming paths to one canonical form (`/foo/bar`).
     */
    private function normalizePath(string $path): string
    {
        $trimmed = '/' . trim($path, '/');
        return $trimmed === '//' ? '/' : $trimmed;
    }
}
