<?php

declare(strict_types=1);

namespace Raven\Lib\Database;

interface QueryProfilerInterface
{
    public function isEnabled(): bool;

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function recordQuery(
        string $connection,
        string $mode,
        string $sql,
        ?array $params,
        float $durationMs,
        bool $success,
        ?string $error = null
    ): void;
}

