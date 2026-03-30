<?php

/**
 * RAVEN CMS
 * ~/private/ext/repo/lib/schema.php
 * Repositories extension schema provider.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/**
 * Ensures extension-owned schema changes (tables/columns/indexes).
 *
 * @param array<string, mixed> $context
 */
return static function (array $context): void {
    if (
        !isset($context['db'], $context['driver'], $context['table'], $context['tables'], $context['storage'])
        || !$context['db'] instanceof \PDO
        || !is_callable($context['table'])
        || !is_callable($context['tables'])
    ) {
        return;
    }

    $db = $context['db'];
    $driver = (string) $context['driver'];
    $tableResolver = $context['table'];   // returns `{prefix}ext_repo`
    $tablesResolver = $context['tables']; // returns `{prefix}ext_repo_{suffix}`
    $storage = is_array($context['storage']) ? $context['storage'] : [];

    // Resolved storage roots, when requested by ext.php:
    // $localRoot = (string) ($storage['local'] ?? '');
    // $panelRoot = (string) ($storage['panel'] ?? '');
    // $publicRoot = (string) ($storage['public'] ?? '');
    //
    // SQL table helpers:
    // $table = $tableResolver();
    // $childTable = $tablesResolver('items');
    //
    // Keep schema operations idempotent. This provider runs on bootstrap/install.
};
