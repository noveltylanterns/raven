<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

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

    public function ensure(PDO $rvnDb, PDO $authDb, string $driver, string $prefix): void
    {
        $components = $this->components;
        $rvnSchemaBuilder = $components->rvnSchemaBuilder();
        $authSchemaBuilder = $components->authSchemaBuilder();
        $seedInstaller = $components->seedInstaller();

        // App schema first so auth/group seeding can rely on group tables.
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

        // Auth schema must exist before user/group relationship seeding.
        $authSchemaBuilder->ensureAuthSchema($authDb, $driver, $prefix);
        $authSchemaBuilder->ensureInviteTokenSchema($authDb, $driver, $prefix);

        $seedInstaller->ensureStockGroups($rvnDb, $driver, $prefix);
        $seedInstaller->ensureSeedPages($rvnDb, $driver, $prefix);
    }
}
