<?php

declare(strict_types=1);

namespace Raven\Core\Routing;

/**
 * Immutable dispatch response contract.
 */
final class RouteDispatchResult
{
    /** @var array<string, string> */
    private array $params;
    private bool $handled;
    private mixed $response;

    /**
     * @param array<string, string> $params
     */
    public function __construct(bool $handled, array $params = [], mixed $response = null)
    {
        $this->handled = $handled;
        $this->params = $params;
        $this->response = $response;
    }

    public function isHandled(): bool
    {
        return $this->handled;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    public function response(): mixed
    {
        return $this->response;
    }

    /**
     * @param array<string, string> $params
     */
    public static function handled(array $params = [], mixed $response = null): self
    {
        return new self(true, $params, $response);
    }

    public static function notHandled(): self
    {
        return new self(false);
    }
}

