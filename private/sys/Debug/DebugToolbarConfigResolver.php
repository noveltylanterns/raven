<?php

declare(strict_types=1);

namespace Raven\Core\Debug;

use Raven\Core\Config;
use Raven\Lib\Config\ConfigValueParser;

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
            'show_on_public' => ConfigValueParser::bool($config->get('debug.show_public', false), false),
            'show_on_panel' => ConfigValueParser::bool($config->get('debug.show_private', false), false),
            'show_benchmarks' => ConfigValueParser::bool($config->get('debug.show_benchmarks', true), true),
            'show_queries' => ConfigValueParser::bool($config->get('debug.show_queries', true), true),
            'show_stack_trace' => ConfigValueParser::bool($config->get('debug.show_trace', true), true),
            'show_request' => ConfigValueParser::bool($config->get('debug.show_request', true), true),
            'show_environment' => ConfigValueParser::bool($config->get('debug.show_environment', true), true),
        ];
    }
}
