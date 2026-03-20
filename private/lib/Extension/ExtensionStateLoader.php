<?php

declare(strict_types=1);

namespace Raven\Lib\Extension;

/**
 * Loads extension enablement/permission state from `private/ext/.state.php`.
 */
final class ExtensionStateLoader
{
    /**
     * @return array{enabled: array<string, mixed>, permissions: array<string, mixed>}
     */
    public function loadState(string $root): array
    {
        $emptyState = ['enabled' => [], 'permissions' => []];
        $statePath = rtrim($root, '/') . '/private/ext/.state.php';
        if (!is_file($statePath)) {
            return $emptyState;
        }

        clearstatcache(true, $statePath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($statePath, true);
        }

        /** @var mixed $rawState */
        $rawState = require $statePath;
        if (!is_array($rawState)) {
            return $emptyState;
        }

        /** @var mixed $enabled */
        $enabled = array_key_exists('enabled', $rawState) ? $rawState['enabled'] : $rawState;
        if (!array_key_exists('enabled', $rawState) && array_key_exists('permissions', $rawState)) {
            $enabled = [];
        }

        /** @var mixed $permissions */
        $permissions = $rawState['permissions'] ?? [];

        return [
            'enabled' => is_array($enabled) ? $enabled : [],
            'permissions' => is_array($permissions) ? $permissions : [],
        ];
    }
}
