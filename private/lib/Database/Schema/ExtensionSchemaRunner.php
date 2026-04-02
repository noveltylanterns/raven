<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;
use Raven\Core\Extension\ExtensionRegistry;
use Raven\Lib\Extension\ExtensionBootstrapContractResolver;

/**
 * Executes extension-owned schema providers during bootstrap.
 */
final class ExtensionSchemaRunner
{
    private TableNameResolver $tables;
    private ExtensionBootstrapContractResolver $bootstrapResolver;

    public function __construct(
        ?TableNameResolver $tables = null,
        ?ExtensionBootstrapContractResolver $bootstrapResolver = null
    )
    {
        $this->tables = $tables ?? new TableNameResolver();
        $this->bootstrapResolver = $bootstrapResolver ?? new ExtensionBootstrapContractResolver();
    }

    public function ensureEnabledExtensionSchemas(PDO $db, string $driver, string $prefix): void
    {
        $root = dirname(__DIR__, 4);
        foreach (ExtensionRegistry::enabledDirectories($root, true) as $directory) {
            $manifest = ExtensionRegistry::readManifest($root, $directory);
            if (!is_array($manifest)) {
                continue;
            }

            $bootstrap = $this->bootstrapResolver->resolve($root, $directory, $manifest);
            if (!$bootstrap['valid']) {
                continue;
            }

            $storage = (array) ($bootstrap['storage'] ?? []);
            if (
                empty($storage['local'])
                && empty($storage['table'])
                && empty($storage['tables'])
                && empty($storage['panel'])
                && empty($storage['public'])
            ) {
                continue;
            }

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
                $tableStem = $this->tables->resolve($driver, $prefix, 'ext_' . $directory);
                $storageLocalPath = $root . '/private/dat/ext/' . $directory;
                $storagePanelPath = $root . '/panel/ext/' . $directory;
                $storagePublicPath = $root . '/public/uploads/ext/' . $directory;
                $storageAuxPaths = [];
                foreach ((array) ($storage['aux'] ?? []) as $auxDirectory) {
                    if (!is_string($auxDirectory) || $auxDirectory === '') {
                        continue;
                    }

                    $storageAuxPaths[$auxDirectory] = $root . '/' . $auxDirectory;
                }
                $tableResolver = static fn (): string => $tableStem;
                $tablesResolver = function (string $suffix) use ($driver, $prefix, $directory, $storage): string {
                    $normalized = strtolower(trim($suffix));
                    if (preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $normalized) !== 1) {
                        throw new \RuntimeException('Invalid extension table suffix requested.');
                    }

                    if (!in_array($normalized, (array) ($storage['tables'] ?? []), true)) {
                        throw new \RuntimeException('Extension table suffix was not provisioned: ' . $normalized);
                    }

                    return $this->tables->resolve($driver, $prefix, 'ext_' . $directory . '_' . $normalized);
                };
                $provider([
                    'db' => $db,
                    'driver' => $driver,
                    'prefix' => $prefix,
                    'extension' => $directory,
                    'storage' => [
                        'local' => $storageLocalPath,
                        'aux' => $storageAuxPaths,
                        'panel' => $storagePanelPath,
                        'public' => $storagePublicPath,
                    ],
                    'table' => $tableResolver,
                    'tables' => $tablesResolver,
                ]);
            } catch (\Throwable $exception) {
                error_log('Raven extension schema provider failed for extension "' . $directory . '": ' . $exception->getMessage());
            }
        }
    }

}
