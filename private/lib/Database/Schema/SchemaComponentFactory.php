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
    private ?RvnSchemaBootstrap $rvnSchemaBootstrap;
    private ?AuthSchemaBuilder $authSchemaBuilder;
    private ?RvnSchemaBuilder $rvnSchemaBuilder;
    private ?SeedInstaller $seedInstaller;
    private ?ExtensionSchemaRunner $extensionSchemaRunner;

    public function __construct(
        ?SchemaIntrospector $schemaIntrospector = null,
        ?TableNameResolver $tableNameResolver = null,
        ?RvnSchemaBootstrap $rvnSchemaBootstrap = null,
        ?AuthSchemaBuilder $authSchemaBuilder = null,
        ?RvnSchemaBuilder $rvnSchemaBuilder = null,
        ?SeedInstaller $seedInstaller = null,
        ?ExtensionSchemaRunner $extensionSchemaRunner = null
    ) {
        $this->schemaIntrospector = $schemaIntrospector;
        $this->tableNameResolver = $tableNameResolver;
        $this->rvnSchemaBootstrap = $rvnSchemaBootstrap;
        $this->authSchemaBuilder = $authSchemaBuilder;
        $this->rvnSchemaBuilder = $rvnSchemaBuilder;
        $this->seedInstaller = $seedInstaller;
        $this->extensionSchemaRunner = $extensionSchemaRunner;
    }

    public function rvnSchemaBootstrap(): RvnSchemaBootstrap
    {
        if ($this->rvnSchemaBootstrap === null) {
            $this->rvnSchemaBootstrap = new RvnSchemaBootstrap();
        }

        return $this->rvnSchemaBootstrap;
    }

    public function authSchemaBuilder(): AuthSchemaBuilder
    {
        if ($this->authSchemaBuilder === null) {
            $this->authSchemaBuilder = new AuthSchemaBuilder($this->schemaIntrospector());
        }

        return $this->authSchemaBuilder;
    }

    public function rvnSchemaBuilder(): RvnSchemaBuilder
    {
        if ($this->rvnSchemaBuilder === null) {
            $this->rvnSchemaBuilder = new RvnSchemaBuilder($this->schemaIntrospector(), $this->tableNameResolver());
        }

        return $this->rvnSchemaBuilder;
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
