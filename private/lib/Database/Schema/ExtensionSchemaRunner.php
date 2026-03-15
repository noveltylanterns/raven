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
    public function ensureEnabledExtensionSchemas(PDO $db, string $driver, string $prefix): void
    {
        $root = dirname(__DIR__, 5);
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
                        return $this->table($driver, $prefix, $table);
                    },
                ]);
            } catch (\Throwable $exception) {
                error_log('Raven extension schema provider failed for extension "' . $directory . '": ' . $exception->getMessage());
            }
        }
    }

    private function table(string $driver, string $prefix, string $table): string
    {
        if ($driver !== 'sqlite') {
            return $prefix . $table;
        }

        if (str_starts_with($table, 'ext_')) {
            return 'extensions.' . $table;
        }

        return match ($table) {
            'pages' => 'main.pages',
            'categories' => 'taxonomy.categories',
            'tags' => 'taxonomy.tags',
            'redirects' => 'taxonomy.redirects',
            'page_categories' => 'main.page_categories',
            'page_tags' => 'main.page_tags',
            'page_images' => 'main.page_images',
            'page_image_variants' => 'main.page_image_variants',
            'groups' => 'auth.groups',
            'user_groups' => 'auth.user_groups',
            'login_failures' => 'auth.login_failures',
            default => 'main.' . $table,
        };
    }
}

