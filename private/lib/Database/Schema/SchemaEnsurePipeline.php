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
        // App schema first so auth/group seeding can rely on group tables.
        $this->components->appSchemaBootstrap()->ensureAppSchema($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePageExtendedColumn($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePageDescriptionColumn($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePageDisplayTitleColumn($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePageGalleryEnabledColumn($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePageSlugScopeUniqueness($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePageImageDisplayColumns($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensureRedirectDescriptionColumn($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensureGroupRoutingColumns($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensureTaxonomyImageColumns($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->ensurePanelPerformanceIndexes($appDb, $driver, $prefix);
        $this->components->appSchemaBuilder()->dropLegacyChannelTable($appDb, $driver, $prefix);
        $this->components->extensionSchemaRunner()->ensureEnabledExtensionSchemas($appDb, $driver, $prefix);

        // Auth schema must exist before user/group relationship seeding.
        $this->components->authSchemaBuilder()->ensureAuthSchema($authDb, $driver, $prefix);
        $this->components->authSchemaBuilder()->ensureInviteTokenSchema($authDb, $driver, $prefix);

        $this->components->seedInstaller()->ensureStockGroups($appDb, $driver, $prefix);
        $this->components->seedInstaller()->ensureSeedPages($appDb, $driver, $prefix);
    }
}
