<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaExtension.php
 * Executes extension-owned schema providers during bootstrap.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;
use Raven\Lib\Database\SqlTable;
use Raven\Lib\Extension\Bootstrap;
use Raven\Lib\Extension\Registry;
use Raven\Lib\Extension\Resolver;

/**
 * Executes extension-owned schema providers during bootstrap.
 */
final class SchemaExtension
{
    private Bootstrap $bootstrapResolver;

    /**
     * Wires the extension bootstrap resolver used to validate provider contracts.
     *
     * @param Bootstrap|null $bootstrapResolver Optional resolver; defaults to the canonical extension bootstrap helper.
     * @return void
     */
    public function __construct(
        ?Bootstrap $bootstrapResolver = null
    )
    {
        $this->bootstrapResolver = $bootstrapResolver ?? new Bootstrap();
    }

    /**
     * Runs each enabled extension's schema provider, skipping extensions without storage declarations.
     *
     * @param PDO    $db     Active Raven database connection.
     * @param string $driver Database driver identifier: sqlite, mysql, or pgsql.
     * @param string $prefix Table name prefix from the site configuration.
     */
    public function ensureExtensionSchemas(PDO $db, string $driver, string $prefix): void
    {
        $root = dirname(__DIR__, 3);
        foreach (Registry::enabledDirectories($root, true) as $directory) {
            $manifest = Registry::readManifest($root, $directory);
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

            $extensionRoot = $root . '/private/ext/' . $directory;
            $schemaPath = Resolver::providerPath($extensionRoot, 'schema.php');
            if ($schemaPath === null) {
                continue;
            }

            /** @var mixed $provider */
            $provider = require $schemaPath;
            if (!is_callable($provider)) {
                error_log('Raven extension schema provider is invalid for extension "' . $directory . '".');
                continue;
            }

            try {
                $tableStem = SqlTable::appTable($driver, $prefix, 'ext_' . $directory);
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

                    return SqlTable::appTable($driver, $prefix, 'ext_' . $directory . '_' . $normalized);
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
