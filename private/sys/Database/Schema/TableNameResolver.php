<?php

declare(strict_types=1);

namespace Raven\Core\Database\Schema;

use Raven\Lib\Database\TableNameResolver as RuntimeTableNameResolver;

/**
 * Resolves logical schema table names to backend-specific physical names.
 */
final class TableNameResolver
{
    public function resolve(string $driver, string $prefix, string $table): string
    {
        return RuntimeTableNameResolver::appTable($driver, $prefix, $table);
    }
}
