<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/RequestProfilerOutput.php
 * Contract for custom request-profiler output renderers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

/**
 * Defines one pluggable request-profiler output renderer.
 */
interface RequestProfilerOutput
{
    /**
     * Returns the stable id used to register and look up this output.
     *
     * @return string Normalized output id.
     */
    public function id(): string;

    /**
     * Renders one custom output from the current request-profiler snapshot.
     *
     * @param array<string, mixed> $snapshot Full request-profiler snapshot payload.
     * @param array<string, mixed> $context Optional caller-provided render context.
     * @return string Rendered output payload, typically HTML.
     */
    public function render(array $snapshot, array $context = []): string;
}
