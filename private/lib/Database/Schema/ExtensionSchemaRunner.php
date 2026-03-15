<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;
use Raven\Core\Extension\ExtensionRegistry;

/**
 * Executes extension-owned schema providers during bootstrap.
 */
final class ExtensionSchemaRunner
{
    private TableNameResolver $tables;

    public function __construct(?TableNameResolver $tables = null)
    {
        $this->tables = $tables ?? new TableNameResolver();
    }

    public function ensureEnabledExtensionSchemas(PDO $db, string $driver, string $prefix): void
    {
        $root = dirname(__DIR__, 4);
        foreach (ExtensionRegistry::enabledDirectories($root, true) as $directory) {
            $schemaPath = $root . '/private/ext/' . $directory . '/lib/schema.php';
            if (!is_file($schemaPath)) {
                continue;
            }

            /** @var mixed $provider */
            $provider = require $schemaPath;
            if (!is_callable($provider)) {
                error_log('Raven extension schema provider is invalid for extension "' . $directory . '".');
                continue;
            }

            try {
                $provider([
                    'db' => $db,
                    'driver' => $driver,
                    'prefix' => $prefix,
                    'extension' => $directory,
                    'table' => function (string $table) use ($driver, $prefix): string {
                        return $this->tables->resolve($driver, $prefix, $table);
                    },
                ]);
            } catch (\Throwable $exception) {
                error_log('Raven extension schema provider failed for extension "' . $directory . '": ' . $exception->getMessage());
            }
        }
    }

}
