<?php

/**
 * RAVEN CMS
 * ~/private/sys/Core/Database/SchemaManager.php
 * Database connection and schema core component.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database;

use PDO;
use Raven\Lib\Database\Schema\AppSchemaBootstrap;
use Raven\Lib\Database\Schema\AppSchemaBuilder;
use Raven\Lib\Database\Schema\AuthSchemaBuilder;
use Raven\Lib\Database\Schema\ExtensionSchemaRunner;
use Raven\Lib\Database\Schema\SchemaIntrospector;
use Raven\Lib\Database\Schema\SeedInstaller;
use Raven\Lib\Database\Schema\TableNameResolver;

/**
 * Creates or updates minimal schema required by Raven.
 */
final class SchemaManager
{
    private ?SchemaIntrospector $schemaIntrospector = null;
    private ?TableNameResolver $tableNameResolver = null;
    private ?AppSchemaBootstrap $appSchemaBootstrap = null;
    private ?AuthSchemaBuilder $authSchemaBuilder = null;
    private ?AppSchemaBuilder $appSchemaBuilder = null;
    private ?SeedInstaller $seedInstaller = null;
    private ?ExtensionSchemaRunner $extensionSchemaRunner = null;

    /**
     * Ensures both app and auth schemas exist for the selected backend.
     */
    public function ensure(PDO $appDb, PDO $authDb, string $driver, string $prefix): void
    {
        // App schema first so auth/group seeding can rely on group tables.
        $this->appSchemaBootstrap()->ensureAppSchema($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePageExtendedColumn($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePageDescriptionColumn($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePageDisplayTitleColumn($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePageGalleryEnabledColumn($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePageSlugScopeUniqueness($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePageImageDisplayColumns($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensureRedirectDescriptionColumn($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensureGroupRoutingColumns($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensureTaxonomyImageColumns($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->ensurePanelPerformanceIndexes($appDb, $driver, $prefix);
        $this->appSchemaBuilder()->dropLegacyChannelTable($appDb, $driver, $prefix);
        $this->extensionSchemaRunner()->ensureEnabledExtensionSchemas($appDb, $driver, $prefix);

        // Auth schema must exist before user/group relationship seeding.
        $this->authSchemaBuilder()->ensureAuthSchema($authDb, $driver, $prefix);
        $this->authSchemaBuilder()->ensureInviteTokenSchema($authDb, $driver, $prefix);

        $this->seedInstaller()->ensureStockGroups($appDb, $driver, $prefix);
        $this->seedInstaller()->ensureSeedPages($appDb, $driver, $prefix);
    }

    private function schemaIntrospector(): SchemaIntrospector
    {
        if ($this->schemaIntrospector === null) {
            $this->schemaIntrospector = new SchemaIntrospector();
        }

        return $this->schemaIntrospector;
    }

    private function tableNameResolver(): TableNameResolver
    {
        if ($this->tableNameResolver === null) {
            $this->tableNameResolver = new TableNameResolver();
        }

        return $this->tableNameResolver;
    }

    private function appSchemaBootstrap(): AppSchemaBootstrap
    {
        if ($this->appSchemaBootstrap === null) {
            $this->appSchemaBootstrap = new AppSchemaBootstrap();
        }

        return $this->appSchemaBootstrap;
    }

    private function authSchemaBuilder(): AuthSchemaBuilder
    {
        if ($this->authSchemaBuilder === null) {
            $this->authSchemaBuilder = new AuthSchemaBuilder($this->schemaIntrospector());
        }

        return $this->authSchemaBuilder;
    }

    private function appSchemaBuilder(): AppSchemaBuilder
    {
        if ($this->appSchemaBuilder === null) {
            $this->appSchemaBuilder = new AppSchemaBuilder($this->schemaIntrospector(), $this->tableNameResolver());
        }

        return $this->appSchemaBuilder;
    }

    private function seedInstaller(): SeedInstaller
    {
        if ($this->seedInstaller === null) {
            $this->seedInstaller = new SeedInstaller($this->tableNameResolver());
        }

        return $this->seedInstaller;
    }

    private function extensionSchemaRunner(): ExtensionSchemaRunner
    {
        if ($this->extensionSchemaRunner === null) {
            $this->extensionSchemaRunner = new ExtensionSchemaRunner($this->tableNameResolver());
        }

        return $this->extensionSchemaRunner;
    }
}
