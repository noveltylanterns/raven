#!/usr/bin/env php
<?php

/**
 * RAVEN CMS
 * ~/build/docs/rvn-docs.php
 * Build-only doc generator entrypoint for appendix reference outputs.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

use Build\Docs\ReferenceGenerator;

require_once __DIR__ . '/lib/ReferenceGenerator.php';

/**
 * @param array<int, string> $argv
 */
function rvn_docs_main(array $argv): int
{
    $parsed = rvn_docs_parse_args($argv);
    $options = $parsed['options'];
    $args = $parsed['args'];

    $generator = new ReferenceGenerator(dirname(__DIR__, 2));
    $supportedTargets = $generator->targetNames();

    if (rvn_docs_bool_option($options, 'help', false, 'h')) {
        rvn_docs_print_help();
        return 0;
    }

    if (rvn_docs_bool_option($options, 'list-targets', false, 'l')) {
        rvn_docs_print_targets($supportedTargets);
        return 0;
    }

    $checkOnly = rvn_docs_bool_option($options, 'check', false, 'c');
    $targets = rvn_docs_collect_targets($args, $options);

    try {
        $result = $generator->run($targets, $checkOnly);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[error] ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }

    $statusVerb = $checkOnly ? 'Checked' : 'Generated';
    echo $statusVerb . ' targets: ' . implode(', ', $result['targets']) . PHP_EOL;

    foreach ($result['files'] as $file) {
        $status = (string) ($file['status'] ?? '');
        $path = (string) ($file['path'] ?? '');
        if ($status === '' || $path === '') {
            continue;
        }

        echo '[' . $status . '] ' . $path . PHP_EOL;
    }

    if ($checkOnly) {
        if ($result['stale'] > 0) {
            fwrite(
                STDERR,
                '[error] Doc outputs are stale (' . $result['stale'] . ' stale, '
                . $result['unchanged'] . ' unchanged). Run php build/docs/rvn-docs.php --all.' . PHP_EOL
            );
            return 2;
        }

        echo '[ok] Doc outputs are up to date (' . $result['unchanged'] . ' unchanged).' . PHP_EOL;
        return 0;
    }

    echo '[ok] Doc generation complete (' . $result['written'] . ' written, '
        . $result['unchanged'] . ' unchanged).' . PHP_EOL;
    return 0;
}

/**
 * @param array<int, string> $argv
 * @return array{options: array<string, mixed>, args: array<int, string>}
 */
function rvn_docs_parse_args(array $argv): array
{
    $args = [];
    $options = [];

    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $token = $argv[$i];

        if (str_starts_with($token, '--')) {
            $raw = substr($token, 2);
            if ($raw === '') {
                continue;
            }

            $key = $raw;
            $value = true;
            if (str_contains($raw, '=')) {
                [$key, $value] = explode('=', $raw, 2);
            } else {
                $next = $argv[$i + 1] ?? null;
                if (is_string($next) && !str_starts_with($next, '-')) {
                    $value = $next;
                    $i++;
                }
            }

            $options[strtolower(trim($key))] = $value;
            continue;
        }

        if (str_starts_with($token, '-')) {
            $flags = str_split(substr($token, 1));
            foreach ($flags as $index => $flag) {
                $key = strtolower(trim($flag));
                if ($key === '') {
                    continue;
                }

                $value = true;
                $next = $argv[$i + 1] ?? null;
                $isLast = $index === count($flags) - 1;
                if ($isLast && is_string($next) && !str_starts_with($next, '-')) {
                    $value = $next;
                    $i++;
                }

                $options[$key] = $value;
            }
            continue;
        }

        $args[] = $token;
    }

    return [
        'options' => $options,
        'args' => $args,
    ];
}

/**
 * @param array<string, mixed> $options
 */
function rvn_docs_bool_option(array $options, string $name, bool $default = false, ?string $short = null): bool
{
    $value = $options[strtolower(trim($name))] ?? null;
    if ($value === null && $short !== null) {
        $value = $options[strtolower(trim($short))] ?? null;
    }

    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_scalar($value)) {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
    }

    return $default;
}

/**
 * @param array<int, string> $args
 * @param array<string, mixed> $options
 * @return array<int, string>
 */
function rvn_docs_collect_targets(array $args, array $options): array
{
    $targets = [];

    if (rvn_docs_bool_option($options, 'all', false, 'a')) {
        $targets[] = 'all';
    }

    $targetRaw = $options['target'] ?? $options['t'] ?? null;
    if (is_scalar($targetRaw)) {
        $parts = explode(',', (string) $targetRaw);
        foreach ($parts as $part) {
            $trimmed = strtolower(trim($part));
            if ($trimmed !== '') {
                $targets[] = $trimmed;
            }
        }
    }

    foreach ($args as $arg) {
        $trimmed = strtolower(trim($arg));
        if ($trimmed !== '') {
            $targets[] = $trimmed;
        }
    }

    if ($targets === []) {
        $targets[] = 'all';
    }

    return $targets;
}

/**
 * @return void
 */
function rvn_docs_print_help(): void
{
    echo 'Usage: php build/docs/rvn-docs.php [--all] [--target=<keys>] [--check] [--list-targets]' . PHP_EOL;
    echo 'Targets: bootstrap, cli, config, core, database, extensions, libraries, templates' . PHP_EOL;
    echo 'Examples:' . PHP_EOL;
    echo '  php build/docs/rvn-docs.php --all' . PHP_EOL;
    echo '  php build/docs/rvn-docs.php --target=cli,config' . PHP_EOL;
    echo '  php build/docs/rvn-docs.php --target=core --check' . PHP_EOL;
}

/**
 * @param array<int, string> $targets
 * @return void
 */
function rvn_docs_print_targets(array $targets): void
{
    echo 'Supported targets:' . PHP_EOL;
    foreach ($targets as $target) {
        echo '- ' . $target . PHP_EOL;
    }
}

exit(rvn_docs_main($argv));
