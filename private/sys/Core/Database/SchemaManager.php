<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Database/SchemaManager.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

require_once dirname(__DIR__, 3) . '/lib/Database/Schema/SchemaIntrospector.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/TableNameResolver.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/AppSchemaBootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/AuthSchemaBuilder.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/AppSchemaBuilder.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/SeedInstaller.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/ExtensionSchemaRunner.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/SchemaComponentFactory.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/SchemaEnsurePipeline.php';
require_once dirname(__DIR__, 3) . '/lib/Database/Schema/SchemaManager.php';

use PDO;
use Raven\Lib\Database\Schema\SchemaManager as LibSchemaManager;

/**
 * Compatibility shim that delegates schema ensure flow to private/lib.
 */
final class SchemaManager
{
    private LibSchemaManager $schemaManager;

    public function __construct(?LibSchemaManager $schemaManager = null)
    {
        $this->schemaManager = $schemaManager ?? new LibSchemaManager();
    }

    /**
     * Ensures both app and auth schemas exist for the selected backend.
     */
    public function ensure(PDO $appDb, PDO $authDb, string $driver, string $prefix): void
    {
        $this->schemaManager->ensure($appDb, $authDb, $driver, $prefix);
    }
}
