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

    public function ensure(PDO $appDb, PDO $authDb, string $driver, string $prefix): void
    {
        $components = $this->components;
        $appSchemaBuilder = $components->appSchemaBuilder();
        $authSchemaBuilder = $components->authSchemaBuilder();
        $seedInstaller = $components->seedInstaller();

        // App schema first so auth/group seeding can rely on group tables.
        $components->appSchemaBootstrap()->ensureAppSchema($appDb, $driver, $prefix);
        $appSchemaBuilder->ensureRootChannelScope($appDb, $driver, $prefix);
        $appSchemaBuilder->migratePageContentStorage($appDb, $driver, $prefix);
        $appSchemaBuilder->ensurePageDescriptionColumn($appDb, $driver, $prefix);
        $appSchemaBuilder->ensurePageDisplayTitleColumn($appDb, $driver, $prefix);
        $appSchemaBuilder->ensurePageGalleryEnabledColumn($appDb, $driver, $prefix);
        $appSchemaBuilder->ensurePageSlugScopeUniqueness($appDb, $driver, $prefix);
        $appSchemaBuilder->ensurePageImageDisplayColumns($appDb, $driver, $prefix);
        $appSchemaBuilder->migratePageTaxonomyPivots($appDb, $driver, $prefix);
        $appSchemaBuilder->ensureRedirectDescriptionColumn($appDb, $driver, $prefix);
        $appSchemaBuilder->ensureGroupRoutingColumns($appDb, $driver, $prefix);
        $appSchemaBuilder->ensureTaxonomySetColumns($appDb, $driver, $prefix);
        $appSchemaBuilder->ensureTaxonomyImageColumns($appDb, $driver, $prefix);
        $appSchemaBuilder->migrateUserGroupPivot($appDb, $driver, $prefix);
        $appSchemaBuilder->migrateLoginFailureStorage($appDb, $driver, $prefix);
        $appSchemaBuilder->ensurePanelPerformanceIndexes($appDb, $driver, $prefix);
        $appSchemaBuilder->dropLegacyChannelTable($appDb, $driver, $prefix);
        $components->extensionSchemaRunner()->ensureEnabledExtensionSchemas($appDb, $driver, $prefix);

        // Auth schema must exist before user/group relationship seeding.
        $authSchemaBuilder->ensureAuthSchema($authDb, $driver, $prefix);
        $authSchemaBuilder->ensureInviteTokenSchema($authDb, $driver, $prefix);

        $seedInstaller->migrateStockGroups($appDb, $driver, $prefix);
        $seedInstaller->ensureStockGroups($appDb, $driver, $prefix);
        $appSchemaBuilder->migrateUserPrimaryGroup($appDb, $driver, $prefix);
        $seedInstaller->ensureSeedPages($appDb, $driver, $prefix);
    }
}
