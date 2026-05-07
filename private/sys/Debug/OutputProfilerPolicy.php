<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/OutputProfilerPolicy.php
 * Output profiler config flag resolver.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

use Raven\Core\Config;
use Raven\Core\Repository\ConfigRead;

/**
 * Shared resolver for output profiler config flags.
 */
final class OutputProfilerPolicy
{
    /**
     * Resolves all output profiler display flags from the site configuration.
     *
     * @param Config $config  The site config instance to read debug flag values from.
     * @return array{
     *   show_on_public: bool,
     *   show_on_panel: bool,
     *   show_benchmarks: bool,
     *   show_queries: bool,
     *   show_stack_trace: bool,
     *   show_request: bool,
     *   show_environment: bool
     * } Resolved boolean flags controlling which profiler sections are enabled.
     */
    public static function fromConfig(Config $config): array
    {
        return [
            'show_on_public' => ConfigRead::bool($config->get('debug.show_public', false), false),
            'show_on_panel' => ConfigRead::bool($config->get('debug.show_private', false), false),
            'show_benchmarks' => ConfigRead::bool($config->get('debug.show_benchmarks', true), true),
            'show_queries' => ConfigRead::bool($config->get('debug.show_queries', true), true),
            'show_stack_trace' => ConfigRead::bool($config->get('debug.show_trace', true), true),
            'show_request' => ConfigRead::bool($config->get('debug.show_request', true), true),
            'show_environment' => ConfigRead::bool($config->get('debug.show_environment', true), true),
        ];
    }
}
