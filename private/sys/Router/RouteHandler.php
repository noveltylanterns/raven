<?php

/**
 * RAVEN CMS
 * ~/private/sys/Router/RouteHandler.php
 * Minimal route registration and dispatch helper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Router;

/**
 * Minimal path router supporting `{param}` and final-segment `{param...}` placeholders.
 */
final class RouteHandler
{
    /** @var array<int, array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    /**
     * Registers one route handler for a method and path pattern.
     *
     * @param string $method HTTP method such as `GET` or `POST`.
     * @param string $pattern Path pattern, optionally using `{param}` or final-segment `{param...}` placeholders.
     * @param callable $handler Route callback invoked with named path params.
     * @return void
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper(trim($method)),
            'regex' => $this->compilePattern($pattern),
            'handler' => $handler,
        ];
    }

    /**
     * Dispatches one normalized route request to the first matching handler.
     *
     * @param RouteRequest $request Normalized route request value object.
     * @return RouteResponse RouteResponse wrapper containing handled state and params.
     */
    public function dispatch(RouteRequest $request): RouteResponse
    {
        $normalizedMethod = $request->method();
        $normalizedPath = $request->path();

        // Dispatch to the first route whose method and regex both match.
        foreach ($this->routes as $route) {
            // Method mismatch is skipped before running regex checks.
            if ($route['method'] !== $normalizedMethod) {
                continue;
            }

            // Continue scanning until a path regex match is found.
            if (!preg_match($route['regex'], $normalizedPath, $matches)) {
                continue;
            }

            $params = [];
            // Copy named capture groups only; numeric captures are implementation detail.
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            $response = ($route['handler'])($params);
            return RouteResponse::handled($params, $response);
        }

        return RouteResponse::notHandled();
    }

    /**
     * Compiles one path pattern into a route-matching regex.
     *
     * @param string $pattern Path pattern, optionally using `{param}` or final-segment `{param...}` placeholders.
     * @return string PCRE regex anchored to the full normalized path.
     */
    private function compilePattern(string $pattern): string
    {
        $normalized = RouteRequest::normalizePath($pattern);

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\.\.\.)?\}/',
            static fn (array $m): string => ($m[2] ?? '') === '...'
                ? '(?P<' . $m[1] . '>.+)'
                : '(?P<' . $m[1] . '>[^/]+)',
            $normalized
        );

        return '#^' . $regex . '$#';
    }
}
