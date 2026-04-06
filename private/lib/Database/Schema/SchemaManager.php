<?php

declare(strict_types=1);

namespace Raven\Lib\Database\Schema;

use PDO;

/**
 * Public schema ensure entrypoint backed by the schema ensure pipeline.
 */
final class SchemaManager
{
    private SchemaEnsurePipeline $pipeline;
    private SchemaEnsureStateStore $stateStore;

    public function __construct(?SchemaEnsurePipeline $pipeline = null, ?SchemaEnsureStateStore $stateStore = null)
    {
        $this->pipeline = $pipeline ?? new SchemaEnsurePipeline();
        $this->stateStore = $stateStore ?? new SchemaEnsureStateStore(dirname(__DIR__, 4));
    }

    public function ensure(PDO $rvnDb, PDO $authDb, string $driver, string $prefix): void
    {
        $this->stateStore->ensureIfChanged($driver, $prefix, function () use ($rvnDb, $authDb, $driver, $prefix): void {
            $this->pipeline->ensure($rvnDb, $authDb, $driver, $prefix);
        });
    }
}
