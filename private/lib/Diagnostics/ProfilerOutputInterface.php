<?php

declare(strict_types=1);

namespace Raven\Lib\Diagnostics;

interface ProfilerOutputInterface
{
    public function id(): string;

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $context
     */
    public function render(array $snapshot, array $context = []): string;
}
