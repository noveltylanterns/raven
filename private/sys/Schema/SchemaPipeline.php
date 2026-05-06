<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaPipeline.php
 * Ordered schema ensure pipeline for app and auth databases.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;

/**
 * Runs schema ensure steps in the required app/auth/seed order.
 */
final class SchemaPipeline
{
    private SchemaComponents $components;

    public function __construct(?SchemaComponents $components = null)
    {
        $this->components = $components ?? new SchemaComponents();
    }

    /**
     * Ensures the full app + auth schema pipeline in one pass.
     *
     * @param PDO $rvnDb App database connection.
     * @param PDO $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensure(PDO $rvnDb, PDO $authDb, string $driver, string $prefix): void
    {
        $this->ensureApp($rvnDb, $driver, $prefix);
        $this->ensureAuth($authDb, $driver, $prefix);
    }

    /**
     * Ensures app-side schema, extension schema, and seed rows.
     *
     * This path intentionally stays independent from auth DB setup so non-auth
     * entrypoints can finish bootstrap without opening the auth connection.
     *
     * @param PDO $rvnDb App database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensureApp(PDO $rvnDb, string $driver, string $prefix): void
    {
        $components = $this->components;
        $schemaBuilder = $components->schemaBuilder();
        $schemaInstaller = $components->schemaInstaller();

        // App schema is always safe to ensure independently from auth setup.
        $components->schemaBootstrap()->ensureSchema($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureRootChannelScope($rvnDb, $driver, $prefix);
        $schemaBuilder->ensurePageScheduleColumns($rvnDb, $driver, $prefix);
        $schemaBuilder->ensurePageDescriptionColumn($rvnDb, $driver, $prefix);
        $schemaBuilder->ensurePageDisplayTitleColumn($rvnDb, $driver, $prefix);
        $schemaBuilder->ensurePageGalleryEnabledColumn($rvnDb, $driver, $prefix);
        $schemaBuilder->ensurePageSlugScopeUniqueness($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureRedirectDescriptionColumn($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureGroupRoutingColumns($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureTaxonomySetColumns($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureTaxonomyImageColumns($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureTaxonomyIconColumn($rvnDb, $driver, $prefix);
        $schemaBuilder->ensurePanelPerformanceIndexes($rvnDb, $driver, $prefix);
        $schemaBuilder->ensureEventLogTable($rvnDb, $driver, $prefix);
        $components->schemaExtension()->ensureExtensionSchemas($rvnDb, $driver, $prefix);

        $schemaInstaller->ensureGroups($rvnDb, $driver, $prefix);
        $schemaInstaller->ensurePages($rvnDb, $driver, $prefix);
    }

    /**
     * Ensures auth-side schema objects only.
     *
     * @param PDO $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensureAuth(PDO $authDb, string $driver, string $prefix): void
    {
        $schemaAuth = $this->components->schemaAuth();
        $schemaAuth->ensureAuthSchema($authDb, $driver, $prefix);
        $schemaAuth->ensureInviteTokenSchema($authDb, $driver, $prefix);
    }
}
