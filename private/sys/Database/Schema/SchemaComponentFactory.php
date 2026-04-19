<?php

/**
 * RAVEN CMS
 * ~/private/sys/Database/Schema/SchemaComponentFactory.php
 * Lazy schema component wiring for bootstrap pipelines.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Database\Schema;

use Raven\Lib\Database\TableNameResolver;

/**
 * Lazily wires schema bootstrap components with shared dependencies.
 */
final class SchemaComponentFactory
{
    private ?SchemaIntrospector $schemaIntrospector;
    private ?TableNameResolver $tableNameResolver;
    private ?SchemaBootstrap $schemaBootstrap;
    private ?AuthSchemaBuilder $authSchemaBuilder;
    private ?SchemaBuilder $schemaBuilder;
    private ?SeedInstaller $seedInstaller;
    private ?ExtensionSchemaRunner $extensionSchemaRunner;

    public function __construct(
        ?SchemaIntrospector $schemaIntrospector = null,
        ?TableNameResolver $tableNameResolver = null,
        ?SchemaBootstrap $schemaBootstrap = null,
        ?AuthSchemaBuilder $authSchemaBuilder = null,
        ?SchemaBuilder $schemaBuilder = null,
        ?SeedInstaller $seedInstaller = null,
        ?ExtensionSchemaRunner $extensionSchemaRunner = null
    ) {
        $this->schemaIntrospector = $schemaIntrospector;
        $this->tableNameResolver = $tableNameResolver;
        $this->schemaBootstrap = $schemaBootstrap;
        $this->authSchemaBuilder = $authSchemaBuilder;
        $this->schemaBuilder = $schemaBuilder;
        $this->seedInstaller = $seedInstaller;
        $this->extensionSchemaRunner = $extensionSchemaRunner;
    }

    /**
     * Returns the base app-schema bootstrapper on first use.
     *
     * @return SchemaBootstrap Bootstrapper for the base Raven app schema.
     */
    public function schemaBootstrap(): SchemaBootstrap
    {
        if ($this->schemaBootstrap === null) {
            $this->schemaBootstrap = new SchemaBootstrap();
        }

        return $this->schemaBootstrap;
    }

    /**
     * Returns the auth-schema builder on first use.
     *
     * @return AuthSchemaBuilder Builder for auth-side schema objects.
     */
    public function authSchemaBuilder(): AuthSchemaBuilder
    {
        if ($this->authSchemaBuilder === null) {
            $this->authSchemaBuilder = new AuthSchemaBuilder($this->schemaIntrospector());
        }

        return $this->authSchemaBuilder;
    }

    /**
     * Returns the app-schema builder on first use.
     *
     * @return SchemaBuilder Builder for app-side schema migrations and backfills.
     */
    public function schemaBuilder(): SchemaBuilder
    {
        if ($this->schemaBuilder === null) {
            $this->schemaBuilder = new SchemaBuilder($this->schemaIntrospector(), $this->tableNameResolver());
        }

        return $this->schemaBuilder;
    }

    /**
     * Returns the seed installer on first use.
     *
     * @return SeedInstaller Installer for stock groups and starter pages.
     */
    public function seedInstaller(): SeedInstaller
    {
        if ($this->seedInstaller === null) {
            $this->seedInstaller = new SeedInstaller($this->tableNameResolver());
        }

        return $this->seedInstaller;
    }

    /**
     * Returns the extension schema runner on first use.
     *
     * @return ExtensionSchemaRunner Runner for extension-owned schema providers.
     */
    public function extensionSchemaRunner(): ExtensionSchemaRunner
    {
        if ($this->extensionSchemaRunner === null) {
            $this->extensionSchemaRunner = new ExtensionSchemaRunner($this->tableNameResolver());
        }

        return $this->extensionSchemaRunner;
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
}
