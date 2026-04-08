<?php

declare(strict_types=1);

namespace Raven\Core\Database\Schema;

use PDO;

/**
 * Runs schema ensure steps in the required app/auth/seed order.
 */
final class SchemaEnsurePipeline
{
    private SchemaComponentFactory $components;

    public function __construct(?SchemaComponentFactory $components = null)
    {
        $this->components = $components ?? new SchemaComponentFactory();
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
        $rvnSchemaBuilder = $components->rvnSchemaBuilder();
        $seedInstaller = $components->seedInstaller();

        // App schema is always safe to ensure independently from auth setup.
        $components->rvnSchemaBootstrap()->ensureRvnSchema($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureRootChannelScope($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensurePageScheduleColumns($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensurePageDescriptionColumn($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensurePageDisplayTitleColumn($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensurePageGalleryEnabledColumn($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensurePageSlugScopeUniqueness($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureRedirectDescriptionColumn($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureGroupRoutingColumns($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureTaxonomySetColumns($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureTaxonomyImageColumns($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureTaxonomyIconColumn($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensurePanelPerformanceIndexes($rvnDb, $driver, $prefix);
        $rvnSchemaBuilder->ensureEventLogTable($rvnDb, $driver, $prefix);
        $components->extensionSchemaRunner()->ensureEnabledExtensionSchemas($rvnDb, $driver, $prefix);

        $seedInstaller->ensureStockGroups($rvnDb, $driver, $prefix);
        $seedInstaller->ensureSeedPages($rvnDb, $driver, $prefix);
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
        $authSchemaBuilder = $this->components->authSchemaBuilder();
        $authSchemaBuilder->ensureAuthSchema($authDb, $driver, $prefix);
        $authSchemaBuilder->ensureInviteTokenSchema($authDb, $driver, $prefix);
    }
}
