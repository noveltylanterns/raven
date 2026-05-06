<?php

/**
 * RAVEN CMS
 * ~/private/sys/Schema/SchemaManager.php
 * Runtime schema ensure entrypoint; gates the bootstrap pipeline behind mtime-based state tracking.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Schema;

use PDO;

/**
 * Runtime schema ensure entrypoint backed by the schema ensure pipeline.
 *
 * Coordinates per-side state stores so the pipeline only fires when schema
 * source files or an explicit invalidation marker are newer than the last
 * successful ensure stamp.
 */
final class SchemaManager
{
    private SchemaPipeline $pipeline;
    private SchemaState $appStateStore;
    private SchemaState $authStateStore;

    /**
     * Wires the pipeline and per-side state stores used by the public ensure methods.
     *
     * @param SchemaPipeline|null   $pipeline       Schema ensure pipeline; defaults to a fresh instance.
     * @param SchemaState|null $appStateStore  App-side state store; defaults to standard paths.
     * @param SchemaState|null $authStateStore Auth-side state store; defaults to auth-specific paths.
     */
    public function __construct(
        ?SchemaPipeline $pipeline = null,
        ?SchemaState $appStateStore = null,
        ?SchemaState $authStateStore = null
    ) {
        $this->pipeline = $pipeline ?? new SchemaPipeline();
        $root = dirname(__DIR__, 3);
        $this->appStateStore = $appStateStore ?? new SchemaState($root);
        $this->authStateStore = $authStateStore ?? new SchemaState(
            $root,
            $root . '/private/dat/.schema_ensure_auth_state.php',
            $root . '/private/dat/.schema_ensure_auth.lock',
            $root . '/private/dat/.schema_ensure_auth.marker'
        );
    }

    /**
     * Ensures both app and auth schema state in one pass.
     *
     * @param PDO    $rvnDb  App database connection.
     * @param PDO    $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     */
    public function ensure(PDO $rvnDb, PDO $authDb, string $driver, string $prefix): void
    {
        $this->ensureApp($rvnDb, $driver, $prefix);
        $this->ensureAuth($authDb, $driver, $prefix);
    }

    /**
     * Ensures app-side schema state only.
     *
     * Intentionally independent from auth DB setup so non-auth entrypoints
     * can finish bootstrap without opening the auth connection.
     *
     * @param PDO    $rvnDb  App database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     */
    public function ensureApp(PDO $rvnDb, string $driver, string $prefix): void
    {
        $this->appStateStore->ensureIfChanged($driver, $prefix, function () use ($rvnDb, $driver, $prefix): void {
            $this->pipeline->ensureApp($rvnDb, $driver, $prefix);
        });
    }

    /**
     * Ensures auth-side schema state only.
     *
     * @param PDO    $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     */
    public function ensureAuth(PDO $authDb, string $driver, string $prefix): void
    {
        $this->authStateStore->ensureIfChanged($driver, $prefix, function () use ($authDb, $driver, $prefix): void {
            $this->pipeline->ensureAuth($authDb, $driver, $prefix);
        });
    }
}
