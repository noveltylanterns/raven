<?php

declare(strict_types=1);

namespace Raven\Core;

/**
 * Minimal path router supporting `{param}` placeholders.
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper(trim($method)),
            'regex' => $this->compilePattern($pattern),
            'handler' => $handler,
        ];
    }

    public function dispatch(RouteRequest $request): RouteDispatchResult
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
            return RouteDispatchResult::handled($params, $response);
        }

        return RouteDispatchResult::notHandled();
    }

    private function compilePattern(string $pattern): string
    {
        $normalized = RouteRequest::normalizePath($pattern);

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $normalized
        );

        return '#^' . $regex . '$#';
    }
}

