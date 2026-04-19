<?php

/**
 * RAVEN CMS
 * ~/private/sys/Routing/Result.php
 * Immutable routing dispatch result value object.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Routing;

/**
 * Immutable dispatch response contract.
 */
final class Result
{
    /** @var array<string, string> */
    private array $params;
    private bool $handled;
    private mixed $response;

    /**
     * @param bool $handled Whether the route was matched and handled.
     * @param array<string, string> $params Named path parameters extracted by the router.
     * @param mixed $response Response value returned by the route handler.
     */
    public function __construct(bool $handled, array $params = [], mixed $response = null)
    {
        $this->handled = $handled;
        $this->params = $params;
        $this->response = $response;
    }

    /**
     * Returns whether the request was matched and handled by a registered route.
     *
     * @return bool True when a route handler was invoked.
     */
    public function isHandled(): bool
    {
        return $this->handled;
    }

    /**
     * Returns the named path parameters extracted from the matched route.
     *
     * @return array<string, string> Named parameter map.
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Returns the response value produced by the matched route handler.
     *
     * @return mixed Handler return value, or null when not handled.
     */
    public function response(): mixed
    {
        return $this->response;
    }

    /**
     * Constructs a handled result with matched parameters and a handler response.
     *
     * @param array<string, string> $params Named path parameters from the matched route.
     * @param mixed $response Response value returned by the route handler.
     * @return self Handled result instance.
     */
    public static function handled(array $params = [], mixed $response = null): self
    {
        return new self(true, $params, $response);
    }

    /**
     * Constructs an unhandled result for requests that matched no registered route.
     *
     * @return self Unhandled result instance.
     */
    public static function notHandled(): self
    {
        return new self(false);
    }
}
