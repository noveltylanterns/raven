<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaComponents.php
 * Lazy schema component wiring for bootstrap pipelines.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

/**
 * Lazily wires schema bootstrap components with shared dependencies.
 */
final class SchemaComponents
{
    private ?SchemaIntrospector $schemaIntrospector;
    private ?SchemaBootstrap $schemaBootstrap;
    private ?SchemaAuth $schemaAuth;
    private ?SchemaBuilder $schemaBuilder;
    private ?SchemaInstaller $schemaInstaller;
    private ?SchemaExtension $schemaExtension;

    /**
     * Accepts optional pre-wired component instances, defaulting to lazy construction on first use.
     *
     * @param SchemaIntrospector|null    $schemaIntrospector    Optional shared introspector; created on first use if null.
     * @param SchemaBootstrap|null       $schemaBootstrap       Optional base app schema bootstrapper; created on first use if null.
     * @param SchemaAuth|null          $schemaAuth      Optional auth schema builder; created on first use if null.
     * @param SchemaBuilder|null       $schemaBuilder   Optional app schema builder; created on first use if null.
     * @param SchemaInstaller|null     $schemaInstaller Optional seed installer; created on first use if null.
     * @param SchemaExtension|null     $schemaExtension Optional extension schema runner; created on first use if null.
     */
    public function __construct(
        ?SchemaIntrospector $schemaIntrospector = null,
        ?SchemaBootstrap $schemaBootstrap = null,
        ?SchemaAuth $schemaAuth = null,
        ?SchemaBuilder $schemaBuilder = null,
        ?SchemaInstaller $schemaInstaller = null,
        ?SchemaExtension $schemaExtension = null
    ) {
        $this->schemaIntrospector = $schemaIntrospector;
        $this->schemaBootstrap = $schemaBootstrap;
        $this->schemaAuth = $schemaAuth;
        $this->schemaBuilder = $schemaBuilder;
        $this->schemaInstaller = $schemaInstaller;
        $this->schemaExtension = $schemaExtension;
    }

    /**
     * Returns the base app-schema bootstrapper on first use.
     *
     * @return SchemaBootstrap Bootstrapper for the base Raven app schema.
     */
    public function schemaBootstrap(): SchemaBootstrap
    {
        if ($this->schemaBootstrap === null) {
            $this->schemaBootstrap = new SchemaBootstrap($this->schemaIntrospector());
        }

        return $this->schemaBootstrap;
    }

    /**
     * Returns the auth-schema builder on first use.
     *
     * @return SchemaAuth Builder for auth-side schema objects.
     */
    public function schemaAuth(): SchemaAuth
    {
        if ($this->schemaAuth === null) {
            $this->schemaAuth = new SchemaAuth($this->schemaIntrospector());
        }

        return $this->schemaAuth;
    }

    /**
     * Returns the app-schema builder on first use.
     *
     * @return SchemaBuilder Builder for app-side schema migrations and backfills.
     */
    public function schemaBuilder(): SchemaBuilder
    {
        if ($this->schemaBuilder === null) {
            $this->schemaBuilder = new SchemaBuilder($this->schemaIntrospector());
        }

        return $this->schemaBuilder;
    }

    /**
     * Returns the seed installer on first use.
     *
     * @return SchemaInstaller Installer for stock groups and starter pages.
     */
    public function schemaInstaller(): SchemaInstaller
    {
        if ($this->schemaInstaller === null) {
            $this->schemaInstaller = new SchemaInstaller();
        }

        return $this->schemaInstaller;
    }

    /**
     * Returns the extension schema runner on first use.
     *
     * @return SchemaExtension Runner for extension-owned schema providers.
     */
    public function schemaExtension(): SchemaExtension
    {
        if ($this->schemaExtension === null) {
            $this->schemaExtension = new SchemaExtension();
        }

        return $this->schemaExtension;
    }

    /**
     * Returns the shared schema introspector on first use.
     *
     * @return SchemaIntrospector Cross-driver table/column/index inspection helper.
     */
    private function schemaIntrospector(): SchemaIntrospector
    {
        if ($this->schemaIntrospector === null) {
            $this->schemaIntrospector = new SchemaIntrospector();
        }

        return $this->schemaIntrospector;
    }
}
