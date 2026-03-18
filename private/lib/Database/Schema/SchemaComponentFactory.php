<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

/**
 * Lazily wires schema bootstrap components with shared dependencies.
 */
final class SchemaComponentFactory
{
    private ?SchemaIntrospector $schemaIntrospector;
    private ?TableNameResolver $tableNameResolver;
    private ?AppSchemaBootstrap $appSchemaBootstrap;
    private ?AuthSchemaBuilder $authSchemaBuilder;
    private ?AppSchemaBuilder $appSchemaBuilder;
    private ?SeedInstaller $seedInstaller;
    private ?ExtensionSchemaRunner $extensionSchemaRunner;

    public function __construct(
        ?SchemaIntrospector $schemaIntrospector = null,
        ?TableNameResolver $tableNameResolver = null,
        ?AppSchemaBootstrap $appSchemaBootstrap = null,
        ?AuthSchemaBuilder $authSchemaBuilder = null,
        ?AppSchemaBuilder $appSchemaBuilder = null,
        ?SeedInstaller $seedInstaller = null,
        ?ExtensionSchemaRunner $extensionSchemaRunner = null
    ) {
        $this->schemaIntrospector = $schemaIntrospector;
        $this->tableNameResolver = $tableNameResolver;
        $this->appSchemaBootstrap = $appSchemaBootstrap;
        $this->authSchemaBuilder = $authSchemaBuilder;
        $this->appSchemaBuilder = $appSchemaBuilder;
        $this->seedInstaller = $seedInstaller;
        $this->extensionSchemaRunner = $extensionSchemaRunner;
    }

    public function appSchemaBootstrap(): AppSchemaBootstrap
    {
        if ($this->appSchemaBootstrap === null) {
            $this->appSchemaBootstrap = new AppSchemaBootstrap();
        }

        return $this->appSchemaBootstrap;
    }

    public function authSchemaBuilder(): AuthSchemaBuilder
    {
        if ($this->authSchemaBuilder === null) {
            $this->authSchemaBuilder = new AuthSchemaBuilder($this->schemaIntrospector());
        }

        return $this->authSchemaBuilder;
    }

    public function appSchemaBuilder(): AppSchemaBuilder
    {
        if ($this->appSchemaBuilder === null) {
            $this->appSchemaBuilder = new AppSchemaBuilder($this->schemaIntrospector(), $this->tableNameResolver());
        }

        return $this->appSchemaBuilder;
    }

    public function seedInstaller(): SeedInstaller
    {
        if ($this->seedInstaller === null) {
            $this->seedInstaller = new SeedInstaller($this->tableNameResolver());
        }

        return $this->seedInstaller;
    }

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
