<?php

/**
 * RAVEN CMS
 * ~/private/sys/Database/SchemaManager.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

require_once dirname(__DIR__, 2) . '/lib/Database/Schema/SchemaIntrospector.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/TableNameResolver.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/RvnSchemaBootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/AuthSchemaBuilder.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/RvnSchemaBuilder.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/SeedInstaller.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/ExtensionSchemaRunner.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/SchemaComponentFactory.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/SchemaEnsurePipeline.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/SchemaEnsureStateStore.php';
require_once dirname(__DIR__, 2) . '/lib/Database/Schema/SchemaManager.php';

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
     *
     * @param PDO $rvnDb App database connection.
     * @param PDO $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensure(PDO $rvnDb, PDO $authDb, string $driver, string $prefix): void
    {
        $this->schemaManager->ensure($rvnDb, $authDb, $driver, $prefix);
    }

    /**
     * Ensures app-side schema state only.
     *
     * @param PDO $rvnDb App database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensureApp(PDO $rvnDb, string $driver, string $prefix): void
    {
        $this->schemaManager->ensureApp($rvnDb, $driver, $prefix);
    }

    /**
     * Ensures auth-side schema state only.
     *
     * @param PDO $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensureAuth(PDO $authDb, string $driver, string $prefix): void
    {
        $this->schemaManager->ensureAuth($authDb, $driver, $prefix);
    }
}
