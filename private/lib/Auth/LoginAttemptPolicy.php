<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

use Raven\Core\Config;
use Raven\Lib\Http\RequestContextResolver;

/**
 * Shared login-throttle policy and request-context normalization helper.
 */
final class LoginAttemptPolicy
{
    public const DEFAULT_MAX = 5;
    public const DEFAULT_WINDOW_SECONDS = 600;
    public const DEFAULT_LOCK_SECONDS = 900;

    private Config $config;
    private RequestContextResolver $requestContextResolver;

    public function __construct(Config $config, RequestContextResolver $requestContextResolver)
    {
        $this->config = $config;
        $this->requestContextResolver = $requestContextResolver;
    }

    public function clientIpAddress(array $server): string
    {
        $normalized = $this->requestContextResolver->normalizeClientIp((string) ($server['REMOTE_ADDR'] ?? ''));
        return $normalized ?? 'unknown';
    }

    public function maxAttempts(): int
    {
        $configured = (int) $this->config->get('session.brute.max', self::DEFAULT_MAX);
        return max(1, $configured);
    }

    public function windowSeconds(): int
    {
        $configured = (int) $this->config->get('session.brute.window', self::DEFAULT_WINDOW_SECONDS);
        return max(1, $configured);
    }

    public function lockSeconds(): int
    {
        $configured = (int) $this->config->get('session.brute.lock', self::DEFAULT_LOCK_SECONDS);
        return max(1, $configured);
    }
}
