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

    public function __construct(?SchemaEnsurePipeline $pipeline = null)
    {
        $this->pipeline = $pipeline ?? new SchemaEnsurePipeline();
    }

    public function ensure(PDO $rvnDb, PDO $authDb, string $driver, string $prefix): void
    {
        $this->pipeline->ensure($rvnDb, $authDb, $driver, $prefix);
    }
}
