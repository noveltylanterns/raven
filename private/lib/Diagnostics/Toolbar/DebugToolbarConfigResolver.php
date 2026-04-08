<?php

declare(strict_types=1);

namespace Raven\Lib\Diagnostics\Toolbar;

use Raven\Lib\Config\Config;

/**
 * Shared resolver for debug-toolbar config flags.
 */
final class DebugToolbarConfigResolver
{
    /**
     * @return array{
     *   show_on_public: bool,
     *   show_on_panel: bool,
     *   show_benchmarks: bool,
     *   show_queries: bool,
     *   show_stack_trace: bool,
     *   show_request: bool,
     *   show_environment: bool
     * }
     */
    public static function fromConfig(Config $config): array
    {
        return [
            'show_on_public' => Config::bool($config->get('debug.show_public', false), false),
            'show_on_panel' => Config::bool($config->get('debug.show_private', false), false),
            'show_benchmarks' => Config::bool($config->get('debug.show_benchmarks', true), true),
            'show_queries' => Config::bool($config->get('debug.show_queries', true), true),
            'show_stack_trace' => Config::bool($config->get('debug.show_trace', true), true),
            'show_request' => Config::bool($config->get('debug.show_request', true), true),
            'show_environment' => Config::bool($config->get('debug.show_environment', true), true),
        ];
    }
}
