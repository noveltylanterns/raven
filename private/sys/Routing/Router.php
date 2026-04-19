<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Router.php
 * Minimal route registration and dispatch helper.
 * Docs: https://raven.lanterns.io
 */

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
     * Registers one route handler for a method and path pattern.
     *
     * @param string $method HTTP method such as `GET` or `POST`.
     * @param string $pattern Path pattern, optionally using `{param}` placeholders.
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
     * @param Request $request Normalized route request value object.
     * @return Result Result wrapper containing handled state and params.
     */
    public function dispatch(Request $request): Result
    {
        $normalizedMethod = $request->method();
        $normalizedPath = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $normalizedMethod) {
                continue;
            }

            if (!preg_match($route['regex'], $normalizedPath, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            $response = ($route['handler'])($params);
            return Result::handled($params, $response);
        }

        return Result::notHandled();
    }

    /**
     * Compiles one path pattern into a route-matching regex.
     *
     * @param string $pattern Path pattern, optionally using `{param}` placeholders.
     * @return string PCRE regex anchored to the full normalized path.
     */
    private function compilePattern(string $pattern): string
    {
        $normalized = Request::normalizePath($pattern);

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $normalized
        );

        return '#^' . $regex . '$#';
    }
}
