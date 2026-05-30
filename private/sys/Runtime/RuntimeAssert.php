<?php

/**
 * RAVEN CMS
 * ~/private/sys/Runtime/RuntimeAssert.php
 * Shared runtime-payload callable-contract assertions for scope builders.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Runtime;

use RuntimeException;

/**
 * Validates required callable factories on runtime payload arrays.
 */
final class RuntimeAssert
{
    /**
     * Asserts every key in one list exists as a callable factory.
     *
     * @param array<string, mixed> $runtime Scope runtime payload array.
     * @param array<int, string> $requiredKeys Ordered list of required runtime factory keys.
     * @param string $scope Scope label used in exception messages.
     * @return void
     * @throws RuntimeException When any required key is missing or not callable.
     */
    public static function assertRequiredCallables(array $runtime, array $requiredKeys, string $scope): void
    {
        // Validate each required key so callers fail fast on incomplete runtime payloads.
        foreach ($requiredKeys as $key) {
            self::requireCallable($runtime, $key, $scope);
        }
    }

    /**
     * Asserts one runtime key exists and resolves to a callable factory.
     *
     * @param array<string, mixed> $runtime Scope runtime payload array.
     * @param string $key Required runtime key that must be callable.
     * @param string $scope Scope label used in exception messages.
     * @return callable Factory callable stored at `$runtime[$key]`.
     * @throws RuntimeException When the key is missing or not callable.
     */
    public static function requireCallable(array $runtime, string $key, string $scope): callable
    {
        $value = $runtime[$key] ?? null;
        // Missing/non-callable entries indicate broken runtime scope wiring.
        if (!is_callable($value)) {
            throw new RuntimeException(
                sprintf('Missing callable runtime factory for scope "%s": %s', $scope, $key)
            );
        }

        return $value;
    }
}
