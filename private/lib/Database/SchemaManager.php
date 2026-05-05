<?php

declare(strict_types=1);

namespace Raven\Lib\Database;

use PDO;

/**
 * Public schema ensure entrypoint backed by the schema ensure pipeline.
 */
final class SchemaManager
{
    private SchemaEnsurePipeline $pipeline;
    private SchemaEnsureStateStore $appStateStore;
    private SchemaEnsureStateStore $authStateStore;

    /**
     * @param SchemaEnsurePipeline|null $pipeline Shared schema ensure pipeline.
     * @param SchemaEnsureStateStore|null $appStateStore App-side schema ensure state store.
     * @param SchemaEnsureStateStore|null $authStateStore Auth-side schema ensure state store.
     * @return void
     */
    public function __construct(
        ?SchemaEnsurePipeline $pipeline = null,
        ?SchemaEnsureStateStore $appStateStore = null,
        ?SchemaEnsureStateStore $authStateStore = null
    )
    {
        $this->pipeline = $pipeline ?? new SchemaEnsurePipeline();
        $root = dirname(__DIR__, 3);
        $this->appStateStore = $appStateStore ?? new SchemaEnsureStateStore($root);
        $this->authStateStore = $authStateStore ?? new SchemaEnsureStateStore(
            $root,
            $root . '/private/dat/.schema_ensure_auth_state.php',
            $root . '/private/dat/.schema_ensure_auth.lock',
            $root . '/private/dat/.schema_ensure_auth.marker'
        );
    }

    /**
     * Ensures both app and auth schema state.
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
     * Ensures app-side schema state only.
     *
     * @param PDO $rvnDb App database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
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
     * @param PDO $authDb Auth database connection.
     * @param string $driver Active PDO driver name.
     * @param string $prefix Active Raven table prefix.
     * @return void
     */
    public function ensureAuth(PDO $authDb, string $driver, string $prefix): void
    {
        $this->authStateStore->ensureIfChanged($driver, $prefix, function () use ($authDb, $driver, $prefix): void {
            $this->pipeline->ensureAuth($authDb, $driver, $prefix);
        });
    }
}
