<?php

/**
 * RAVEN CMS
 * ~/private/sys/Debug/ProfilerConfigResolver.php
 * Output profiler config flag resolver.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Debug;

use Raven\Core\Config;
use Raven\Lib\Parser\ConfigParser;

/**
 * Shared resolver for output profiler config flags.
 */
final class ProfilerConfigResolver
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
            'show_on_public' => ConfigParser::bool($config->get('debug.show_public', false), false),
            'show_on_panel' => ConfigParser::bool($config->get('debug.show_private', false), false),
            'show_benchmarks' => ConfigParser::bool($config->get('debug.show_benchmarks', true), true),
            'show_queries' => ConfigParser::bool($config->get('debug.show_queries', true), true),
            'show_stack_trace' => ConfigParser::bool($config->get('debug.show_trace', true), true),
            'show_request' => ConfigParser::bool($config->get('debug.show_request', true), true),
            'show_environment' => ConfigParser::bool($config->get('debug.show_environment', true), true),
        ];
    }
}
