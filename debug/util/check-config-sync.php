<?php

/**
 * RAVEN CMS
 * ~/debug/util/check-config-sync.php
 * CLI check for config.php and config.php.dist key-tree parity.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This script intentionally compares structure, not secret values.

declare(strict_types=1);

/**
 * Returns one normalized node map describing every path in a config tree.
 *
 * Example output keys:
 * - `site` => array
 * - `site.domain` => scalar
 *
 * @param mixed $node
 * @param array<string, string> $map
 */
function collectConfigNodes(mixed $node, string $path, array &$map): void
{
    if (is_array($node)) {
        if ($path !== '') {
            $map[$path] = 'array';
        }

        foreach ($node as $key => $value) {
            $segment = (string) $key;
            $childPath = $path === '' ? $segment : $path . '.' . $segment;
            collectConfigNodes($value, $childPath, $map);
        }

        return;
    }

    if ($path !== '') {
        $map[$path] = 'scalar';
    }
}

/**
 * Loads one PHP config file and validates that it returns an array.
 *
 * @return array<string, mixed>
 */
function loadConfigFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing config file: ' . $path);
    }

    /** @var mixed $loaded */
    $loaded = require $path;

    if (!is_array($loaded)) {
        throw new RuntimeException('Config file must return array: ' . $path);
    }

    return $loaded;
}

/**
 * Prints one list block with consistent formatting.
 *
 * @param array<int, string> $lines
 */
function printBlock(string $title, array $lines): void
{
    echo $title . PHP_EOL;
    foreach ($lines as $line) {
        echo '  - ' . $line . PHP_EOL;
    }
}

$projectRoot = dirname(__DIR__, 2);
$configPath = $argv[1] ?? ($projectRoot . '/private/dat/config.php');
$distPath = $argv[2] ?? ($projectRoot . '/private/dat/config.php.dist');

try {
    $config = loadConfigFile($configPath);
    $dist = loadConfigFile($distPath);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Config sync check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$configNodes = [];
$distNodes = [];
collectConfigNodes($config, '', $configNodes);
collectConfigNodes($dist, '', $distNodes);

$onlyInConfig = array_keys(array_diff_key($configNodes, $distNodes));
$onlyInDist = array_keys(array_diff_key($distNodes, $configNodes));

$kindMismatches = [];
foreach (array_intersect(array_keys($configNodes), array_keys($distNodes)) as $path) {
    if ($configNodes[$path] !== $distNodes[$path]) {
        $kindMismatches[] = $path . ' (' . $configNodes[$path] . ' vs ' . $distNodes[$path] . ')';
    }
}

sort($onlyInConfig);
sort($onlyInDist);
sort($kindMismatches);

if ($onlyInConfig === [] && $onlyInDist === [] && $kindMismatches === []) {
    echo 'Config sync OK: key tree matches between private/dat/config.php and private/dat/config.php.dist.' . PHP_EOL;
    exit(0);
}

fwrite(STDERR, 'Config sync check failed: config structures are out of sync.' . PHP_EOL);

if ($onlyInConfig !== []) {
    printBlock('Paths present only in private/dat/config.php:', $onlyInConfig);
}

if ($onlyInDist !== []) {
    printBlock('Paths present only in private/dat/config.php.dist:', $onlyInDist);
}

if ($kindMismatches !== []) {
    printBlock('Paths with array/scalar shape mismatches:', $kindMismatches);
}

fwrite(STDERR, 'Update private/dat/config.php.dist to mirror private/dat/config.php key structure.' . PHP_EOL);
exit(1);
